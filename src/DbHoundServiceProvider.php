<?php

namespace Foodics\DbHound;

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Capture\CapturePolicy;
use Foodics\DbHound\Capture\Fingerprinter;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Commands\AnalyzeCommand;
use Foodics\DbHound\Commands\FlushCommand;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Stores\RedisStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
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
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Store::class, fn (Application $app) => new RedisStore(
            $app->make('redis')->connection($app->make('config')->get('db-hound.redis.connection')),
            (string) $app->make('config')->get('db-hound.redis.prefix', 'db-hound:'),
        ));

        $this->app->singleton(Recorder::class, fn (Application $app) => new Recorder(
            $app->make(Store::class),
            $app->make(Fingerprinter::class),
            $app->make('encrypter'),
            CapturePolicy::fromConfig((array) $app->make('config')->get('db-hound', [])),
            storeBindings: (bool) $app->make('config')->get('db-hound.store_bindings', true),
            sampleTtlSeconds: (int) round((float) $app->make('config')->get('db-hound.sample_ttl_days', 7) * 86400),
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
}
