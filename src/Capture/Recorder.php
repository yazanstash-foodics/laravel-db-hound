<?php

namespace Foodics\DbHound\Capture;

use Foodics\DbHound\Contracts\Store;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
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

    /**
     * After a Store failure, batches are dropped without contacting the Store
     * for this many seconds, and the failure is logged at most this often:
     * an outage costs each process one timeout per window, not one per batch.
     */
    private const FAILURE_COOL_DOWN = 60;

    /** @var array<string, QueryAggregate> */
    private array $pending = [];

    private int $pendingQueries = 0;

    private bool $shutdownRegistered = false;

    /** When the Store last failed (Unix seconds), while it is cooling down. */
    private ?int $failedAt = null;

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
        private readonly bool $storeBindings = true,
        private readonly int $sampleTtlSeconds = 7 * 86400,
        private readonly ?LoggerInterface $logger = null,
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
            $this->pending[$key] = new QueryAggregate(
                connection: $event->connectionName,
                hash: $fingerprint->hash,
                sql: $fingerprint->sql,
                sample: $this->storeBindings ? $this->sample($event) : null,
                count: 1,
                totalMs: $ms,
                maxMs: $ms,
                firstSeen: $now,
                lastSeen: $now,
                sampleExpiresAt: $this->storeBindings ? $now + $this->sampleTtlSeconds : null,
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
        $now = Carbon::now()->getTimestamp();

        // Fail open: while the Store is cooling down, the batch is dropped.
        if ($this->failedAt !== null && $now - $this->failedAt < self::FAILURE_COOL_DOWN) {
            return;
        }

        $this->failedAt = null;

        try {
            $this->store->push($batch);
        } catch (Throwable $e) {
            // Fail open: the batch is dropped.
            $this->failedAt = $now;
            $this->reportFailure($e);
        }
    }

    /**
     * Log a Store failure; the cool-down that follows it keeps an outage
     * from flooding the logs.
     */
    private function reportFailure(Throwable $e): void
    {
        try {
            $this->logger?->warning(
                'db-hound could not push captured queries to its Store; the batch was dropped. Captured queries are dropped without contacting the Store for the next minute.',
                ['exception' => $e],
            );
        } catch (Throwable) {
            // Logging must not break the application either.
        }
    }

    /**
     * The encrypted Sample: the raw SQL (which may hold inline literals) and
     * its bindings.
     */
    private function sample(QueryExecuted $event): string
    {
        return (new Sample($event->sql, $event->connection->prepareBindings($event->bindings)))->encrypt($this->encrypter);
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
