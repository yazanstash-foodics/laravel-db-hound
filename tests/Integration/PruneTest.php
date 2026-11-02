<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Persist a Fingerprint last seen `$daysAgo` days ago.
 */
function persistSeen(string $hash, float $daysAgo): void
{
    $seenAt = now()->subSeconds((int) round($daysAgo * 86400))->getTimestamp();

    app(QueryRepository::class)->merge([new QueryAggregate(
        connection: 'mysql',
        hash: $hash,
        sql: "select * from `hound_items` where `{$hash}` = ?",
        sample: null,
        count: 1,
        totalMs: 10.0,
        maxMs: 10.0,
        firstSeen: $seenAt,
        lastSeen: $seenAt,
    )]);
}

/**
 * Hashes of the Fingerprints still persisted.
 *
 * @return list<string>
 */
function persistedHashes(): array
{
    return array_map(fn (QueryAggregate $aggregate) => $aggregate->hash, app(QueryRepository::class)->all());
}

it('deletes Fingerprints not seen for the configured number of days and keeps the rest', function () {
    config()->set('db-hound.prune_after_days', 30);

    persistSeen('ancient', 90);
    persistSeen('stale', 31);
    persistSeen('recent', 29);
    persistSeen('today', 0);

    $this->artisan('db-hound:prune')
        ->expectsOutputToContain('Pruned 2 Fingerprint(s)')
        ->assertSuccessful();

    expect(persistedHashes())->toEqualCanonicalizing(['recent', 'today']);
});

it('deletes in chunks of at most the configured size', function () {
    config()->set('db-hound.prune_after_days', 30);
    config()->set('db-hound.prune.chunk_size', 2);

    foreach (['a', 'b', 'c', 'd', 'e'] as $hash) {
        persistSeen($hash, 40);
    }
    persistSeen('recent', 1);

    // Rows left in the table after each DELETE statement the prune runs.
    $remaining = [];
    DB::listen(function (QueryExecuted $query) use (&$remaining) {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
            $remaining[] = DB::table(QueryRepository::TABLE)->count();
        }
    });

    $this->artisan('db-hound:prune')
        ->expectsOutputToContain('Pruned 5 Fingerprint(s)')
        ->assertSuccessful();

    $deletedPerStatement = array_map(
        fn (int $left, int $before) => $before - $left,
        $remaining,
        [6, ...array_slice($remaining, 0, -1)],
    );

    expect(persistedHashes())->toBe(['recent'])
        ->and(array_sum($deletedPerStatement))->toBe(5)
        ->and(max($deletedPerStatement))->toBeLessThanOrEqual(2);
});

it('prunes a chunk larger than MySQL allows placeholders in one statement', function () {
    config()->set('db-hound.prune_after_days', 30);
    config()->set('db-hound.prune.chunk_size', 70_000);

    $staleAt = now()->subDays(60);
    foreach (array_chunk(range(1, 70_000), 2_000) as $chunk) {
        DB::table(QueryRepository::TABLE)->insert(array_map(fn (int $n) => [
            'connection' => 'mysql',
            'fingerprint_hash' => "stale-{$n}",
            'normalized_sql' => 'select 1',
            'count' => 1,
            'total_ms' => 1.0,
            'max_ms' => 1.0,
            'first_seen_at' => $staleAt,
            'last_seen_at' => $staleAt,
        ], $chunk));
    }
    persistSeen('today', 0);

    $this->artisan('db-hound:prune')
        ->expectsOutputToContain('Pruned 70000 Fingerprint(s)')
        ->assertSuccessful();

    expect(persistedHashes())->toBe(['today']);
});

it('refuses to prune with a retention or chunk size that is not a positive number', function (string $key, mixed $value) {
    config()->set('db-hound.prune_after_days', 30);
    config()->set('db-hound.prune.chunk_size', 1000);
    config()->set("db-hound.{$key}", $value);

    persistSeen('stale', 40);
    persistSeen('today', 0);

    $this->artisan('db-hound:prune')
        ->expectsOutputToContain($key)
        ->assertFailed();

    expect(persistedHashes())->toEqualCanonicalizing(['stale', 'today']);
})->with([
    'no retention' => ['prune_after_days', 0],
    'negative retention' => ['prune_after_days', -5],
    'empty chunks' => ['prune.chunk_size', 0],
    'infinite retention' => ['prune_after_days', INF],
    'retention that is not a number' => ['prune_after_days', NAN],
]);

it('keeps every Fingerprint when the retention reaches back before any date the table can hold', function () {
    config()->set('db-hound.prune_after_days', 1e15);

    persistSeen('ancient', 400);
    persistSeen('today', 0);

    $this->artisan('db-hound:prune')
        ->expectsOutputToContain('Pruned 0 Fingerprint(s)')
        ->assertSuccessful();

    expect(persistedHashes())->toEqualCanonicalizing(['ancient', 'today']);
});
