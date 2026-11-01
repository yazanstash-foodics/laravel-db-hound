<?php

namespace Foodics\DbHound\Persistence;

use Foodics\DbHound\Capture\QueryAggregate;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Persisted aggregates in `db_hound_queries`.
 */
final class QueryRepository
{
    public const TABLE = 'db_hound_queries';

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Merge aggregates into the table: counts and totals add up, max keeps the
     * largest, first/last seen widen, and the first stored Sample is kept.
     *
     * @param  list<QueryAggregate>  $aggregates
     */
    public function merge(array $aggregates): void
    {
        $this->db->transaction(function () use ($aggregates) {
            foreach ($aggregates as $aggregate) {
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
                $merged->merge($aggregate);

                $this->db->table(self::TABLE)
                    ->where('id', $existing->id)
                    ->update($this->columns($merged));
            }
        });
    }

    /**
     * @return list<QueryAggregate>
     */
    public function all(): array
    {
        return $this->db->table(self::TABLE)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => $this->hydrate($row))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(QueryAggregate $aggregate): array
    {
        return [
            'sample' => $aggregate->sample,
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
