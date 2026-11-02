<?php

use Foodics\DbHound\Drivers\MySqlDriver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('hound_notes');
    Schema::create('hound_notes', function (Blueprint $table) {
        $table->id();
        $table->string('note');
    });
    DB::table('hound_notes')->insert(array_map(fn ($i) => ['note' => "n{$i}"], range(1, 200)));
});

afterEach(function () {
    if (DB::select("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'") !== []) {
        DB::statement('SET SESSION explain_json_format_version = 1');
    }
    Schema::dropIfExists('hound_notes');
    Schema::dropIfExists('hound_growing');
});

it('detects a full scan when the server defaults to EXPLAIN JSON format version 2', function () {
    if (DB::select("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'") === []) {
        $this->markTestSkipped('This MySQL server has no explain_json_format_version (added in 8.3).');
    }

    DB::statement('SET SESSION explain_json_format_version = 2');

    $plan = (new MySqlDriver(DB::connection()))->explain('select * from `hound_notes` where `note` = ?', ['n1']);

    expect($plan->accesses)->toHaveCount(1)
        ->and($plan->accesses[0]->table)->toBe('hound_notes')
        ->and($plan->accesses[0]->isFullScan())->toBeTrue()
        ->and($plan->accesses[0]->rowsExamined)->toBeGreaterThan(0)
        ->and(DB::selectOne("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'")->Value)->toBe('2');
});

it('reads each index type and visibility', function () {
    Schema::table('hound_notes', function (Blueprint $table) {
        $table->fullText('note', 'ft_note');
        $table->index('note', 'idx_note');
    });
    DB::statement('ALTER TABLE `hound_notes` ALTER INDEX `idx_note` INVISIBLE');

    $indexes = [];

    foreach ((new MySqlDriver(DB::connection()))->indexes('hound_notes') as $index) {
        $indexes[$index->name] = [$index->type, $index->visible];
    }

    ksort($indexes);

    expect($indexes)->toBe([
        'PRIMARY' => ['BTREE', true],
        'ft_note' => ['FULLTEXT', true],
        'idx_note' => ['BTREE', false],
    ]);
});

it('counts rows inserted after an earlier row count, without ANALYZE TABLE', function () {
    Schema::create('hound_growing', function (Blueprint $table) {
        $table->id();
        $table->string('status');
    });
    $driver = new MySqlDriver(DB::connection());

    // Caches the table's statistics in information_schema while it is empty.
    expect($driver->rowCount('hound_growing'))->toBe(0);

    DB::table('hound_growing')->insert(array_map(fn ($i) => ['status' => 'open'], range(1, 1500)));

    expect($driver->rowCount('hound_growing'))->toBeGreaterThanOrEqual(1000)
        ->and(DB::selectOne('SELECT @@SESSION.information_schema_stats_expiry AS expiry')->expiry)->toBe(86400);
});

it('never sends a statement that is not a SELECT to EXPLAIN', function (string $sql, array $bindings) {
    $sent = [];
    DB::listen(function ($query) use (&$sent) {
        $sent[] = $query->sql;
    });

    expect(fn () => (new MySqlDriver(DB::connection()))->explain($sql, $bindings))->toThrow(InvalidArgumentException::class)
        ->and($sent)->toBe([])
        ->and(DB::table('hound_notes')->count())->toBe(200);
})->with([
    'delete' => ['delete from `hound_notes` where `note` = ?', ['n1']],
    'update' => ['update `hound_notes` set `note` = ? where `note` = ?', ['x', 'n1']],
    'insert' => ['insert into `hound_notes` (`note`) values (?)', ['x']],
    'EXPLAIN ANALYZE' => ['analyze select * from `hound_notes`', []],
    'a DELETE with select in a comment' => ["delete from `hound_notes` -- select\n", []],
    'a second statement after a SELECT' => ['select * from `hound_notes`; delete from `hound_notes`', []],
]);

it('refuses to EXPLAIN inside an open transaction rather than commit it', function () {
    DB::beginTransaction();
    DB::table('hound_notes')->where('note', 'n1')->delete();

    try {
        expect(fn () => (new MySqlDriver(DB::connection()))->explain('select * from `hound_notes` where `note` = ?', ['n2']))
            ->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }

    expect(DB::table('hound_notes')->where('note', 'n1')->exists())->toBeTrue();
});

it('fails the EXPLAIN instead of silently re-running it outside the guards when the connection drops', function () {
    config()->set('database.connections.hound_killer', config('database.connections.mysql'));
    $victim = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $killed = false;

    DB::listen(function ($query) use ($victim, &$killed) {
        if (! $killed && $query->connectionName === 'mysql' && $query->sql === 'SET SESSION max_execution_time = 1234') {
            $killed = true;
            DB::connection('hound_killer')->statement('KILL CONNECTION '.$victim);
        }
    });

    $driver = new MySqlDriver(DB::connection(), 1234);

    expect(fn () => $driver->explain('select * from `hound_notes` where `note` = ?', ['n1']))->toThrow(PDOException::class)
        ->and($killed)->toBeTrue()
        // The connection recovers for the next Fingerprint.
        ->and($driver->explain('select * from `hound_notes` where `note` = ?', ['n1'])->accesses)->toHaveCount(1);
});

it('keeps the guards and the EXPLAIN on the read session of a read/write connection', function (bool $sticky) {
    $mysql = config('database.connections.mysql');
    config()->set('database.connections.hound_rw', $mysql + [
        'read' => ['host' => [$mysql['host']]],
        'write' => ['host' => [$mysql['host']]],
        'sticky' => $sticky,
    ]);
    $connection = DB::connection('hound_rw');
    $read = $connection->getReadPdo();
    $readId = (int) $read->query('SELECT CONNECTION_ID()')->fetchColumn();
    expect((int) $connection->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn())->not->toBe($readId);

    // A temporary table exists only on its own session: only the read session can EXPLAIN a query on it.
    $read->exec('CREATE TEMPORARY TABLE hound_read_only_here (id INT, note VARCHAR(20))');

    $guards = null;
    DB::listen(function ($query) use ($readId, &$guards) {
        if ($query->connectionName === 'hound_rw' && str_starts_with($query->sql, 'EXPLAIN')) {
            $guards = (array) DB::selectOne(
                "SELECT
                    (SELECT v.VARIABLE_VALUE FROM performance_schema.variables_by_thread v
                     WHERE v.THREAD_ID = t.THREAD_ID AND v.VARIABLE_NAME = 'max_execution_time') AS ms,
                    (SELECT CONCAT(x.STATE, ' ', x.ACCESS_MODE) FROM performance_schema.events_transactions_current x
                     WHERE x.THREAD_ID = t.THREAD_ID) AS trx
                 FROM performance_schema.threads t WHERE t.PROCESSLIST_ID = ?",
                [$readId],
            );
        }
    });

    $driver = new MySqlDriver($connection, 1234);
    $driver->rowCount('hound_notes');
    $plan = $driver->explain('select * from `hound_read_only_here` where `note` = ?', ['n1']);

    expect($plan->accesses[0]->table)->toBe('hound_read_only_here')
        ->and($guards)->toBe(['ms' => '1234', 'trx' => 'ACTIVE READ ONLY']);
})->with(['not sticky' => false, 'sticky' => true]);
