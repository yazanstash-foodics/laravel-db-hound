<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    Storage::fake('local');

    Schema::dropIfExists('hound_orders');
    Schema::create('hound_orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('branch_id')->index();
        $table->string('customer_email');
    });

    $rows = array_map(fn (int $i) => ['branch_id' => $i % 40, 'customer_email' => "customer{$i}@example.test"], range(1, 2000));
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('hound_orders')->insert($chunk);
    }
    DB::statement('ANALYZE TABLE hound_orders');

    config()->set('db-hound.enabled', true);
    // Local queries are fast: let every latency qualify.
    config()->set('db-hound.analysis.min_max_ms', 0);
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_orders');
    Schema::dropIfExists('hound_gone');
    Schema::dropIfExists('hound_tokens');
});

/**
 * The Dump files db-hound wrote, by path on the local disk.
 *
 * @return list<string>
 */
function dumpFiles(): array
{
    return array_values(array_filter(
        Storage::disk('local')->files('db-hound'),
        fn (string $path) => str_starts_with(basename($path), 'dump-'),
    ));
}

/**
 * Persist a Fingerprint as `db-hound:flush` would, with the given statistics
 * and no Sample.
 */
function persistDumpFingerprint(string $sql, int $count, float $avgMs, ?float $maxMs = null, string $connection = 'mysql'): void
{
    app(QueryRepository::class)->merge([new QueryAggregate(
        connection: $connection,
        hash: md5($sql),
        sql: $sql,
        sample: null,
        count: $count,
        totalMs: $count * $avgMs,
        maxMs: $maxMs ?? $avgMs,
        firstSeen: 1_790_000_000,
        lastSeen: 1_790_000_500,
    )]);
}

/**
 * Run `db-hound:dump` and return the lines of the one Dump it wrote, decoded.
 *
 * @return list<array<string, mixed>>
 */
function runDump(): array
{
    test()->artisan('db-hound:dump')->assertSuccessful();

    $files = dumpFiles();
    expect($files)->toHaveCount(1);

    $contents = Storage::disk('local')->get($files[0]);
    $lines = $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));

    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

it('writes one line per qualifying Fingerprint with its normalized SQL and decrypted Sample', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $lines = runDump();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['normalized_sql'])->toBe('select * from `hound_orders` where `customer_email` = ?')
        ->and($lines[0]['sample'])->toBe([
            'sql' => 'select * from `hound_orders` where `customer_email` = ?',
            'bindings' => ['secret-binding-value@example.test'],
        ]);
});

it('includes the raw EXPLAIN FORMAT=JSON output of the Sample, unmodified', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $lines = runDump();

    $expected = (string) array_values((array) DB::selectOne(
        'EXPLAIN FORMAT=JSON select * from `hound_orders` where `customer_email` = ?',
        ['secret-binding-value@example.test'],
    ))[0];

    expect($lines[0]['explain'])->toBe($expected)
        ->and($lines[0]['explain'])->toContain('secret-binding-value@example.test')
        ->and($lines[0]['explained'])->toBe([
            'sql' => 'select * from `hound_orders` where `customer_email` = ?',
            'bindings' => ['secret-binding-value@example.test'],
        ]);
});

it('includes the schema context and impact stats of each Fingerprint', function () {
    $this->travelTo(Carbon::parse('2026-11-01 09:30:15'));

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $line = runDump()[0];

    expect($line['connection'])->toBe('mysql')
        ->and($line['analysis_connection'])->toBe('mysql')
        ->and($line['fingerprint_hash'])->toBe(DB::table('db_hound_queries')->value('fingerprint_hash'))
        ->and($line['schema'])->toHaveKeys(['hound_orders'])->toHaveCount(1)
        ->and($line['schema']['hound_orders']['row_count'])->toBeGreaterThan(1000)
        ->and($line['schema']['hound_orders']['indexes'])->toEqualCanonicalizing([
            ['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'type' => 'BTREE', 'visible' => true],
            ['name' => 'hound_orders_branch_id_index', 'columns' => ['branch_id'], 'unique' => false, 'type' => 'BTREE', 'visible' => true],
        ])
        ->and($line['schema']['hound_orders']['columns'])->toBe([
            'id' => 'bigint unsigned',
            'branch_id' => 'bigint unsigned',
            'customer_email' => 'varchar(255)',
        ])
        ->and($line['stats']['count'])->toBe(50)
        ->and($line['stats']['avg_ms'])->toEqualWithDelta($line['stats']['total_ms'] / 50, 0.0001)
        ->and($line['stats']['impact'])->toEqualWithDelta($line['stats']['total_ms'], 0.0001)
        ->and($line['stats']['max_ms'])->toBeGreaterThanOrEqual($line['stats']['avg_ms'])
        ->and($line['stats']['first_seen'])->toBe('2026-11-01T09:30:15+00:00')
        ->and($line['stats']['last_seen'])->toBe('2026-11-01T09:30:15+00:00');
});

it('writes the Dump owner-only with a -SENSITIVE.jsonl name, whatever the umask', function () {
    $this->travelTo(Carbon::parse('2026-11-01 09:30:15'));

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $umask = umask(0);

    try {
        $this->artisan('db-hound:dump')->assertSuccessful();
    } finally {
        umask($umask);
    }

    expect(dumpFiles())->toBe(['db-hound/dump-2026-11-01_093015-SENSITIVE.jsonl']);

    clearstatcache();
    expect(fileperms(Storage::disk('local')->path(dumpFiles()[0])) & 0777)->toBe(0600);
});

it('warns that the Dump holds production data on every run, even an empty one', function () {
    $this->artisan('db-hound:dump')
        ->expectsOutputToContain('SENSITIVE: the Dump contains production data')
        ->expectsOutputToContain('0 Fingerprint(s) written to')
        ->assertSuccessful();

    $this->travel(1)->seconds();

    $this->artisan('db-hound:dump')
        ->expectsOutputToContain('SENSITIVE: the Dump contains production data')
        ->assertSuccessful();

    expect(dumpFiles())->toHaveCount(2);
});

it('dumps only the Fingerprints analyze would explain, highest impact first', function () {
    config()->set('db-hound.analysis.min_max_ms', 500);
    config()->set('db-hound.analysis.max_fingerprints', 2);

    persistDumpFingerprint('select * from `hound_orders` where `customer_email` = ?', count: 100, avgMs: 60.0);
    persistDumpFingerprint('select * from `hound_orders` where `id` = ?', count: 100, avgMs: 600.0);
    persistDumpFingerprint('select * from `hound_orders` where `customer_email` like ?', count: 100, avgMs: 55.0);
    // Below the count threshold, too fast, an ignored table, an ignored statement.
    persistDumpFingerprint('select * from `hound_orders` where `branch_id` = ?', count: 9, avgMs: 600.0);
    persistDumpFingerprint('select * from `hound_orders` where `branch_id` > ?', count: 100, avgMs: 49.0, maxMs: 499.0);
    persistDumpFingerprint('select * from `jobs` where `queue` = ?', count: 100, avgMs: 600.0);
    persistDumpFingerprint('show tables', count: 100, avgMs: 600.0);

    expect(array_column(runDump(), 'normalized_sql'))->toBe([
        'select * from `hound_orders` where `id` = ?',
        'select * from `hound_orders` where `customer_email` = ?',
    ]);
});

it('explains and inspects on the mapped analysis connection, in a read-only transaction under max_execution_time', function () {
    // A second name for the same server: the read replica of a real deployment.
    config()->set('database.connections.mysql_replica', config('database.connections.mysql'));
    config()->set('db-hound.analysis.connections', ['mysql' => 'mysql_replica']);
    config()->set('db-hound.analysis.max_execution_time_ms', 1234);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries) {
        $queries[] = [$event->connectionName, strtolower(trim($event->sql))];
    });

    $line = runDump()[0];

    $analysis = array_values(array_filter(
        $queries,
        fn (array $query) => str_starts_with($query[1], 'explain') || str_contains($query[1], 'information_schema'),
    ));
    $sql = array_column($queries, 1);
    $explain = array_search('explain format=json select * from `hound_orders` where `customer_email` = ?', $sql, true);

    expect($line['analysis_connection'])->toBe('mysql_replica')
        ->and($analysis)->not->toBeEmpty()
        ->and(array_unique(array_column($analysis, 0)))->toBe(['mysql_replica'])
        ->and($explain)->toBeInt()
        ->and(array_slice($sql, $explain - 2, 4))->toBe([
            'start transaction read only',
            'set session max_execution_time = 1234',
            'explain format=json select * from `hound_orders` where `customer_email` = ?',
            'rollback',
        ])
        ->and(implode("\n", $sql))->not->toContain('analyze select');
});

it('explains an UPDATE as the SELECT of the rows it touches, never running the write', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'secret-binding-value@example.test')->update(['branch_id' => 4242]);
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $before = DB::table('hound_orders')->where('branch_id', 4242)->count();
    $line = runDump()[0];

    expect($line['normalized_sql'])->toBe('update `hound_orders` set `branch_id` = ? where `customer_email` = ?')
        ->and($line['sample'])->toBe([
            'sql' => 'update `hound_orders` set `branch_id` = ? where `customer_email` = ?',
            'bindings' => [4242, 'secret-binding-value@example.test'],
        ])
        ->and($line['explained'])->toBe([
            'sql' => 'select * from `hound_orders` where `customer_email` = ?',
            'bindings' => ['secret-binding-value@example.test'],
        ])
        ->and(json_decode($line['explain'], true)['query_block']['table']['access_type'])->toBe('ALL')
        ->and($line['explain_error'])->toBeNull()
        ->and(DB::table('hound_orders')->where('branch_id', 4242)->count())->toBe($before);
});

it('explains a Fingerprint without a Sample with placeholder bindings', function () {
    persistDumpFingerprint('select * from `hound_orders` where `branch_id` = ? and `customer_email` = ?', count: 100, avgMs: 60.0);

    $line = runDump()[0];

    expect($line['sample'])->toBeNull()
        ->and($line['explained'])->toBe([
            'sql' => 'select * from `hound_orders` where `branch_id` = ? and `customer_email` = ?',
            'bindings' => [1, 'a'],
        ])
        ->and(json_decode($line['explain'], true))->toHaveKey('query_block')
        ->and($line['explain_error'])->toBeNull();
});

it('keeps the line of a Fingerprint whose EXPLAIN fails, with the failure, and dumps the others', function () {
    Schema::create('hound_gone', function (Blueprint $table) {
        $table->id();
        $table->string('email');
    });

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_gone')->where('email', 'secret-binding-value@example.test')->get();
        DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    Schema::drop('hound_gone');

    $lines = array_column(runDump(), null, 'normalized_sql');
    $gone = $lines['select * from `hound_gone` where `email` = ?'];

    expect($lines)->toHaveCount(2)
        ->and($gone['explain'])->toBeNull()
        ->and($gone['explain_error'])->toContain('EXPLAIN failed')->toContain('42S02')
        ->and($gone['sample']['bindings'])->toBe(['secret-binding-value@example.test'])
        ->and($lines['select * from `hound_orders` where `customer_email` = ?']['explain'])->toBeString();
});

it('keeps binary binding values exact, base64-encoded', function () {
    Schema::create('hound_tokens', function (Blueprint $table) {
        $table->id();
        $table->binary('token', 16, fixed: true);
    });

    $token = "\xff\x00\xfe".str_repeat("\x01", 13);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_tokens')->where('token', $token)->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $line = runDump()[0];

    expect($line['sample']['bindings'])->toBe([['base64' => base64_encode($token)]])
        ->and($line['explained']['bindings'])->toBe([['base64' => base64_encode($token)]])
        ->and($line['explain'])->toBeString()->not->toContain("\u{FFFD}");
});

it('refuses to write the Dump to a disk that is not on this host', function () {
    Storage::extend('remote', function () {
        $adapter = Mockery::mock(FilesystemAdapter::class);

        return new Illuminate\Filesystem\FilesystemAdapter(new Filesystem($adapter), $adapter);
    });
    config()->set('filesystems.disks.remote', ['driver' => 'remote']);
    config()->set('db-hound.report.disk', 'remote');

    $this->artisan('db-hound:dump')
        ->expectsOutputToContain('SENSITIVE: the Dump contains production data')
        ->expectsOutputToContain('The Dump is only written to a local disk, and the report disk [remote] is not one.')
        ->assertFailed();
});

it('does not capture the queries it runs itself', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')->where('customer_email', 'someone@example.test')->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();
    $this->artisan('db-hound:dump')->assertSuccessful();

    expect(pushedRedisValues())->toBe([])
        ->and(config('db-hound.enabled'))->toBeTrue();
});

it('still dumps the EXPLAIN of a query the SQL parser cannot parse, saying why its schema is missing', function () {
    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_orders')
            ->where('customer_email', 'secret-binding-value@example.test')
            ->orderByRaw('customer_email collate utf8mb4_bin')
            ->get();
    }

    $this->artisan('db-hound:flush --force')->assertSuccessful();

    $line = runDump()[0];

    expect($line['explain'])->toBeString()
        ->and($line['explain_error'])->toBeNull()
        ->and($line['schema'])->toBe([])
        ->and($line['schema_error'])->toStartWith('cannot find its tables: the SQL parser could not parse it')
        // Always a JSON object, keyed by table, even when empty.
        ->and(Storage::disk('local')->get(dumpFiles()[0]))->toContain('"schema":{}');
});

it('keeps the line of a Fingerprint whose connection is no longer configured, and dumps the others', function () {
    persistDumpFingerprint('select * from `hound_orders` where `customer_email` = ?', count: 100, avgMs: 60.0, connection: 'legacy_removed');
    persistDumpFingerprint('select * from `hound_orders` where `branch_id` = ?', count: 100, avgMs: 60.0);

    $lines = array_column(runDump(), null, 'connection');

    expect($lines)->toHaveKeys(['mysql', 'legacy_removed'])
        ->and($lines['legacy_removed']['explain'])->toBeNull()
        ->and($lines['legacy_removed']['explained'])->toBeNull()
        ->and($lines['legacy_removed']['explain_error'])->toBe('opening analysis connection [legacy_removed] failed (InvalidArgumentException)')
        ->and($lines['legacy_removed']['schema'])->toBe([])
        ->and($lines['legacy_removed']['schema_error'])->toBe('opening analysis connection [legacy_removed] failed (InvalidArgumentException)')
        ->and($lines['mysql']['explain'])->toBeString();
});

it('says why the schema of a table read from a named database is missing', function () {
    $database = DB::connection()->getDatabaseName();
    persistDumpFingerprint("select * from `{$database}`.`hound_orders` where `customer_email` = ?", count: 100, avgMs: 60.0);

    $line = runDump()[0];

    expect($line['explain'])->toBeString()
        ->and($line['schema'])->toBe([])
        ->and($line['schema_error'])->toBe("`hound_orders` is read from database `{$database}`, and db-hound inspects only the connection's default database");
});

it('warns that the Dump holds production data even when run with --quiet', function () {
    $output = new BufferedOutput;

    expect(Artisan::call('db-hound:dump', ['--quiet' => true], $output))->toBe(0)
        ->and($output->fetch())->toContain('SENSITIVE: the Dump contains production data')
        ->and(dumpFiles())->toHaveCount(1);
});

it('refuses to write the Dump to a local disk that is served publicly', function (array $served) {
    $root = sys_get_temp_dir().'/db-hound-served-'.bin2hex(random_bytes(4));
    config()->set('filesystems.disks.served', ['driver' => 'local', 'root' => $root, ...$served]);
    config()->set('db-hound.report.disk', 'served');

    try {
        $this->artisan('db-hound:dump')
            ->expectsOutputToContain('SENSITIVE: the Dump contains production data')
            ->expectsOutputToContain('The Dump is never written to a disk served publicly, and the report disk [served] has a url or public visibility.')
            ->assertFailed();

        expect(glob($root.'/db-hound/dump-*') ?: [])->toBe([]);
    } finally {
        @rmdir($root.'/db-hound');
        @rmdir($root);
    }
})->with([
    'like Laravel\'s public disk' => [['url' => 'http://localhost/storage', 'visibility' => 'public']],
    'with a url' => [['url' => 'http://localhost/storage']],
    'with public visibility' => [['visibility' => 'public']],
]);

/**
 * A stream wrapper over real files that records, for each file it creates,
 * the mode the file had the moment it came into existence.
 */
final class CreationModeProbe
{
    /** @var array<string, int> */
    public static array $createdWithMode = [];

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $stream = null;

    private static function real(string $path): string
    {
        return substr($path, strlen('dbhoundprobe://'));
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $real = self::real($path);
        $stream = @fopen($real, $mode);

        if ($stream === false) {
            return false;
        }

        if (str_contains($mode, 'x')) {
            clearstatcache(true, $real);
            self::$createdWithMode[basename($real)] = fileperms($real) & 0777;
        }

        $this->stream = $stream;

        return true;
    }

    public function stream_write(string $data): int
    {
        return (int) fwrite($this->stream, $data);
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->stream, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->stream);
    }

    public function stream_flush(): bool
    {
        return fflush($this->stream);
    }

    public function stream_close(): void
    {
        fclose($this->stream);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->stream);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return @stat(self::real($path));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return @mkdir(self::real($path), $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE));
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return $option === STREAM_META_ACCESS && chmod(self::real($path), $value);
    }

    public function unlink(string $path): bool
    {
        return @unlink(self::real($path));
    }
}

it('creates the Dump owner-only from the start, so nobody can open it before it is locked down', function () {
    $root = sys_get_temp_dir().'/db-hound-probe-'.bin2hex(random_bytes(4));
    mkdir($root);
    stream_wrapper_register('dbhoundprobe', CreationModeProbe::class);
    CreationModeProbe::$createdWithMode = [];
    config()->set('filesystems.disks.probed', ['driver' => 'local', 'root' => 'dbhoundprobe://'.$root]);
    config()->set('db-hound.report.disk', 'probed');

    $umask = umask(0);

    try {
        $this->artisan('db-hound:dump')->assertSuccessful();
        $umaskAfter = umask();
    } finally {
        umask($umask);
        stream_wrapper_unregister('dbhoundprobe');
        array_map('unlink', glob($root.'/db-hound/*') ?: []);
        @rmdir($root.'/db-hound');
        @rmdir($root);
    }

    expect(CreationModeProbe::$createdWithMode)->toHaveCount(1)
        ->and(array_values(CreationModeProbe::$createdWithMode))->toBe([0600])
        // The process's umask is left as it was.
        ->and($umaskAfter)->toBe(0);
});
