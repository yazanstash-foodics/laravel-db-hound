<?php

namespace Foodics\DbHound\Capture;

use Foodics\DbHound\Contracts\Store;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Aggregates executed queries in memory per process and pushes them to the
 * Store in micro-batches: one push every `batchSize` queries, plus whatever
 * remains when the process shuts down.
 *
 * Sampling is decided once per unit of work (request, job, command), on its
 * first query, so a sampled unit captures all of its queries.
 */
final class Recorder
{
    /** At most this many shapes are cached; the cache is emptied when full. */
    private const SHAPE_CACHE_SIZE = 500;

    /** Longer SQL (inline literals, huge lists) is too unlikely to repeat to cache. */
    private const SHAPE_CACHE_MAX_SQL_LENGTH = 4096;

    /** @var array<string, QueryAggregate> */
    private array $pending = [];

    private int $pendingQueries = 0;

    private bool $shutdownRegistered = false;

    /** Whether the current unit of work is sampled; null until its first query. */
    private ?bool $sampled = null;

    /**
     * Fingerprints of recently seen SQL, keyed by connection and raw SQL;
     * false when the query is not captured. Bindings make repeats (N+1 loops,
     * the same lookup per request) common, so most queries skip normalizing.
     *
     * @var array<string, Fingerprint|false>
     */
    private array $shapes = [];

    public function __construct(
        private readonly Store $store,
        private readonly Fingerprinter $fingerprinter,
        private readonly Encrypter $encrypter,
        private readonly CapturePolicy $policy = new CapturePolicy,
        private readonly int $batchSize = 50,
    ) {}

    public function record(QueryExecuted $event): void
    {
        $this->sampled ??= $this->policy->samplesUnit();

        $ms = (float) $event->time;

        if (! $this->sampled || ! $this->policy->admits($event->connectionName, $ms)) {
            return;
        }

        $fingerprint = $this->shape($event);

        if ($fingerprint === false) {
            return;
        }

        $this->registerShutdown();

        $key = $event->connectionName.':'.$fingerprint->hash;
        $now = Carbon::now()->getTimestamp();

        if (isset($this->pending[$key])) {
            $this->pending[$key]->record($ms, $now);
        } else {
            $sample = new Sample($event->sql, $event->connection->prepareBindings($event->bindings));

            $this->pending[$key] = new QueryAggregate(
                connection: $event->connectionName,
                hash: $fingerprint->hash,
                sql: $fingerprint->sql,
                sample: $sample->encrypt($this->encrypter),
                count: 1,
                totalMs: $ms,
                maxMs: $ms,
                firstSeen: $now,
                lastSeen: $now,
            );
        }

        if (++$this->pendingQueries >= $this->batchSize) {
            $this->push();
        }
    }

    /**
     * A new request, job or Octane task begins in this (possibly long-lived)
     * process: its first query decides afresh whether it is sampled.
     */
    public function beginUnitOfWork(): void
    {
        $this->sampled = null;
    }

    /**
     * Push everything aggregated so far. Never throws: capture must not break
     * the application.
     */
    public function push(): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = array_values($this->pending);
        $this->pending = [];
        $this->pendingQueries = 0;

        try {
            $this->store->push($batch);
        } catch (Throwable) {
            // Fail open: the batch is dropped.
        }
    }

    /**
     * The query's Fingerprint, or false when its shape is not captured
     * (excluded tables, or SQL that cannot be normalized safely).
     */
    private function shape(QueryExecuted $event): Fingerprint|false
    {
        $key = $event->connectionName."\0".$event->sql;

        if (isset($this->shapes[$key])) {
            return $this->shapes[$key];
        }

        $fingerprint = $this->fingerprinter->fingerprint($event->sql);

        $shape = $fingerprint !== null
            && $this->policy->admitsTables($fingerprint->sql, $event->connection->getTablePrefix())
                ? $fingerprint
                : false;

        if (strlen($event->sql) <= self::SHAPE_CACHE_MAX_SQL_LENGTH) {
            if (count($this->shapes) >= self::SHAPE_CACHE_SIZE) {
                $this->shapes = [];
            }

            $this->shapes[$key] = $shape;
        }

        return $shape;
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(fn () => $this->push());
    }
}
