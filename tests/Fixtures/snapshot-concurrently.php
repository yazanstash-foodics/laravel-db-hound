<?php

/*
 * Child process for the Store contract: boots the package in a fresh
 * application, prints "ready", waits for a line on stdin, then takes a
 * snapshot of the Store DB_HOUND_STORE selects, so it overlaps one the test
 * takes at the same moment. Prints the summed count of the aggregates it got.
 *
 * Usage: php snapshot-concurrently.php
 */

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\DbHoundServiceProvider;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: [
    'extra' => [
        'providers' => [DbHoundServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);

IntegrationTestCase::configureServices($app['config']);

$store = $app->make(Store::class);
$store->stats(); // connect before the race starts

fwrite(STDOUT, "ready\n");
fgets(STDIN);

$snapshot = $store->snapshot();

fwrite(STDOUT, (string) array_sum(array_map(fn (QueryAggregate $aggregate) => $aggregate->count, $snapshot)));

exit(0);
