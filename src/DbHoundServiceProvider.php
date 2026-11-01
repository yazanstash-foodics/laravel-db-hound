<?php

namespace Foodics\DbHound;

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Capture\CapturePolicy;
use Foodics\DbHound\Capture\Fingerprinter;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Commands\AnalyzeCommand;
use Foodics\DbHound\Commands\FlushCommand;
use Foodics\DbHound\Commands\PruneCommand;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Stores\RedisStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class DbHoundServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('db-hound')
            ->hasConfigFile()
            ->hasMigration('create_db_hound_queries_table')
            ->hasCommands([
                FlushCommand::class,
                AnalyzeCommand::class,
                PruneCommand::class,
            ]);
    }

    /** How long a scheduled flush's overlap lock outlives a flush that died. */
    private const FLUSH_LOCK_MINUTES = 10;

    /** How long a scheduled prune's overlap lock outlives a prune that died. */
    private const PRUNE_LOCK_MINUTES = 60;

    public function packageRegistered(): void
    {
        $this->app->singleton(Store::class, fn (Application $app) => new RedisStore(
            fn () => $this->storeRedisConnection($app),
            (string) $app->make('config')->get('db-hound.redis.prefix', 'db-hound:'),
        ));

        $this->app->singleton(Recorder::class, fn (Application $app) => new Recorder(
            $app->make(Store::class),
            $app->make(Fingerprinter::class),
            $app->make('encrypter'),
            CapturePolicy::fromConfig((array) $app->make('config')->get('db-hound', [])),
            storeBindings: (bool) $app->make('config')->get('db-hound.store_bindings', true),
            sampleTtlSeconds: (int) round((float) $app->make('config')->get('db-hound.sample_ttl_days', 7) * 86400),
            logger: $app->make('log'),
        ));

        $this->app->bind(Analyzer::class, fn (Application $app) => new Analyzer(
            $app->make('encrypter'),
            qualification: Qualification::fromConfig(
                (array) $app->make('config')->get('db-hound.analysis', []),
                array_map(
                    fn (mixed $connection) => is_array($connection) ? (string) ($connection['prefix'] ?? '') : '',
                    (array) $app->make('config')->get('database.connections', []),
                ),
            ),
        ));

        $this->app->bind(QueryRepository::class, fn (Application $app) => new QueryRepository(
            $app->make('db')->connection(),
            storeBindings: (bool) $app->make('config')->get('db-hound.store_bindings', true),
        ));
    }

    /**
     * A connection of its own to the configured Redis connection, with
     * db-hound's short timeouts: a slow or unreachable Redis costs a captured
     * batch at most `redis.timeout` seconds per attempt, and the app's own
     * connections keep their settings.
     */
    private function storeRedisConnection(Application $app): Connection
    {
        $config = $app->make('config');
        $name = (string) $config->get('db-hound.redis.connection', 'default');
        $redis = (array) $config->get('database.redis', []);
        $timeout = (float) $config->get('db-hound.redis.timeout', 0.05);

        $manager = new RedisManager($app, (string) ($redis['client'] ?? 'phpredis'), [
            'options' => (array) ($redis['options'] ?? []),
            $name => [
                ...(array) ($redis[$name] ?? []),
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'read_write_timeout' => $timeout,
            ],
        ]);

        return $manager->connection($name);
    }

    /**
     * Events that start a new unit of work inside a long-lived process. FPM
     * requests and Artisan commands each get a fresh process instead.
     */
    private const UNIT_OF_WORK_EVENTS = [
        JobProcessing::class,
        'Laravel\\Octane\\Events\\RequestReceived',
        'Laravel\\Octane\\Events\\TaskReceived',
        'Laravel\\Octane\\Events\\TickReceived',
    ];

    public function packageBooted(): void
    {
        $this->scheduleFlush();
        $this->schedulePrune();

        $this->app->make('events')->listen(self::UNIT_OF_WORK_EVENTS, function (mixed $event = null) {
            // A job run synchronously is part of the unit that dispatched it.
            if ($event instanceof JobProcessing && $event->job instanceof SyncJob) {
                return;
            }

            // No Recorder yet means no sampling decision yet to forget.
            if ($this->app->resolved(Recorder::class)) {
                $this->app->make(Recorder::class)->beginUnitOfWork();
            }
        });

        $this->app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $event) {
            if (! $this->app->make('config')->get('db-hound.enabled')) {
                return;
            }

            try {
                $this->app->make(Recorder::class)->record($event);
            } catch (Throwable) {
                // Capture fails open: it must never break the query it observes.
            }
        });
    }

    /**
     * Flush every minute while capture is enabled; the command itself only
     * acts once a threshold is reached. Apps that set `flush.schedule` to
     * false register db-hound:flush on their own terms instead.
     *
     * One server runs it at a time, and a flush that dies without releasing
     * its overlap lock blocks the next ones for minutes, not a day.
     */
    private function scheduleFlush(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! $this->app->make('config')->get('db-hound.flush.schedule', true)) {
                return;
            }

            $schedule->command(FlushCommand::class)
                ->everyMinute()
                ->withoutOverlapping(expiresAt: self::FLUSH_LOCK_MINUTES)
                ->onOneServer()
                ->when(fn () => (bool) $this->app->make('config')->get('db-hound.enabled'));
        });
    }

    /**
     * Prune once a day once the table exists, whether or not capture is on:
     * retention is the only window Reports have, so it keeps running after
     * capture is turned off, while an install that has not migrated yet gets
     * nothing run against it. Apps that set `prune.schedule` to false
     * register db-hound:prune on their own terms instead.
     */
    private function schedulePrune(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! $this->app->make('config')->get('db-hound.prune.schedule', true)) {
                return;
            }

            $schedule->command(PruneCommand::class)
                ->daily()
                ->withoutOverlapping(expiresAt: self::PRUNE_LOCK_MINUTES)
                ->onOneServer()
                ->when(fn () => $this->queryTableExists());
        });
    }

    /**
     * Whether `db_hound_queries` exists. An unreachable database counts as
     * no: a schedule filter that throws would abort the app's whole
     * schedule:run, not just the prune.
     */
    private function queryTableExists(): bool
    {
        try {
            return $this->app->make('db')->connection()->getSchemaBuilder()->hasTable(QueryRepository::TABLE);
        } catch (Throwable) {
            return false;
        }
    }
}
