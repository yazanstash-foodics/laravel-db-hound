<?php

namespace Foodics\DbHound\Persistence;

use Foodics\DbHound\Capture\QueryAggregate;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Persisted aggregates in `db_hound_queries`.
 */
final class QueryRepository
{
    public const TABLE = 'db_hound_queries';

    /**
     * With `$storeBindings` off no Sample is kept or handed out: merging
     * erases any stored before the switch was turned off.
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly bool $storeBindings = true,
    ) {}

    /**
     * Merge aggregates into the table: counts and totals add up, max keeps the
     * largest, first/last seen widen, and the stored Sample is kept until it
     * expires. Expired Samples are erased from every row, and every Sample is
     * when bindings are not stored.
     *
     * @param  list<QueryAggregate>  $aggregates
     */
    public function merge(array $aggregates): void
    {
        $now = Carbon::now()->getTimestamp();

        $this->db->transaction(function () use ($aggregates, $now) {
            $this->db->table(self::TABLE)
                ->when(
                    $this->storeBindings,
                    fn ($query) => $query->where('sample_expires_at', '<=', $this->toDateTime($now)),
                    fn ($query) => $query->whereNotNull('sample'),
                )
                ->update(['sample' => null, 'sample_expires_at' => null]);

            foreach ($aggregates as $aggregate) {
                $this->forgetUnusableSample($aggregate, $now);

                $existing = $this->db->table(self::TABLE)
                    ->where('connection', $aggregate->connection)
                    ->where('fingerprint_hash', $aggregate->hash)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $this->db->table(self::TABLE)->insert([
                        ...$this->columns($aggregate),
                        'connection' => $aggregate->connection,
                        'fingerprint_hash' => $aggregate->hash,
                        'normalized_sql' => $aggregate->sql,
                        'created_at' => Carbon::now(),
                    ]);

                    continue;
                }

                $merged = $this->hydrate($existing);
                $merged->merge($aggregate, $now);

                $this->db->table(self::TABLE)
                    ->where('id', $existing->id)
                    ->update($this->columns($merged));
            }
        });
    }

    /**
     * Every persisted aggregate; a Sample that has expired, or any Sample
     * when bindings are not stored, is left out.
     *
     * @return list<QueryAggregate>
     */
    public function all(): array
    {
        $now = Carbon::now()->getTimestamp();

        return $this->db->table(self::TABLE)
            ->orderBy('id')
            ->get()
            ->map(function (object $row) use ($now) {
                $aggregate = $this->hydrate($row);
                $this->forgetUnusableSample($aggregate, $now);

                return $aggregate;
            })
            ->values()
            ->all();
    }

    /**
     * Delete every Fingerprint last seen before `$cutoff`, at most
     * `$chunkSize` rows per statement so no single delete holds its locks
     * for long. Returns how many rows were deleted.
     */
    public function deleteNotSeenSince(int $cutoff, int $chunkSize): int
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException("Chunk size must be at least 1, got {$chunkSize}.");
        }

        $cutoff = $this->toDateTime($cutoff);
        $deleted = 0;

        // One statement per chunk, with no id list to bind: a statement holds
        // at most 65,535 placeholders. It walks the last_seen_at index, so it
        // only locks stale rows, and a row a concurrent flush has just seen
        // again no longer matches.
        do {
            $chunk = $this->db->table(self::TABLE)
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('last_seen_at')
                ->orderBy('id')
                ->limit($chunkSize)
                ->delete();

            $deleted += $chunk;
        } while ($chunk === $chunkSize);

        return $deleted;
    }

    /**
     * Drop the aggregate's Sample once it has expired, or always when
     * bindings are not stored.
     */
    private function forgetUnusableSample(QueryAggregate $aggregate, int $now): void
    {
        if ($this->storeBindings) {
            $aggregate->expireSample($now);

            return;
        }

        $aggregate->sample = null;
        $aggregate->sampleExpiresAt = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(QueryAggregate $aggregate): array
    {
        return [
            'sample' => $aggregate->sample,
            'sample_expires_at' => $aggregate->sampleExpiresAt === null ? null : $this->toDateTime($aggregate->sampleExpiresAt),
            'count' => $aggregate->count,
            'total_ms' => $aggregate->totalMs,
            'max_ms' => $aggregate->maxMs,
            'first_seen_at' => $this->toDateTime($aggregate->firstSeen),
            'last_seen_at' => $this->toDateTime($aggregate->lastSeen),
            'updated_at' => Carbon::now(),
        ];
    }

    private function hydrate(object $row): QueryAggregate
    {
        return new QueryAggregate(
            connection: (string) $row->connection,
            hash: (string) $row->fingerprint_hash,
            sql: (string) $row->normalized_sql,
            sample: $row->sample === null ? null : (string) $row->sample,
            count: (int) $row->count,
            totalMs: (float) $row->total_ms,
            maxMs: (float) $row->max_ms,
            firstSeen: Carbon::parse($row->first_seen_at, date_default_timezone_get())->getTimestamp(),
            lastSeen: Carbon::parse($row->last_seen_at, date_default_timezone_get())->getTimestamp(),
            sampleExpiresAt: $row->sample_expires_at === null
                ? null
                : Carbon::parse($row->sample_expires_at, date_default_timezone_get())->getTimestamp(),
        );
    }

    /**
     * DATETIME columns hold app-timezone wall-clock time, like the Laravel
     * timestamps beside them; Carbon 3 would otherwise build a UTC instance.
     */
    private function toDateTime(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, date_default_timezone_get());
    }
}
