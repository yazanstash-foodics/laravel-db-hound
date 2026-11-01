<?php

namespace Foodics\DbHound;

use Foodics\DbHound\Capture\Fingerprinter;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Commands\AnalyzeCommand;
use Foodics\DbHound\Commands\FlushCommand;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Stores\RedisStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
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
        ));

        $this->app->bind(QueryRepository::class, fn (Application $app) => new QueryRepository(
            $app->make('db')->connection(),
        ));
    }

    public function packageBooted(): void
    {
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
