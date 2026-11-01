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
 */
final class Recorder
{
    /** @var array<string, QueryAggregate> */
    private array $pending = [];

    private int $pendingQueries = 0;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Store $store,
        private readonly Fingerprinter $fingerprinter,
        private readonly Encrypter $encrypter,
        private readonly int $batchSize = 50,
    ) {}

    public function record(QueryExecuted $event): void
    {
        $this->registerShutdown();

        $fingerprint = $this->fingerprinter->fingerprint($event->sql);
        $key = $event->connectionName.':'.$fingerprint->hash;
        $now = Carbon::now()->getTimestamp();
        $ms = (float) $event->time;

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

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(fn () => $this->push());
    }
}
