<?php

namespace Foodics\DbHound;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Foodics\DbHound\Commands\DbHoundCommand;

class DbHoundServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('db-hound')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_db_hound_table')
            ->hasCommand(DbHoundCommand::class);
    }
}
