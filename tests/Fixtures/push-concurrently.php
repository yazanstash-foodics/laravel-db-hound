<?php

/*
 * Child process for FlushTest: boots the package in a fresh application and
 * pushes <push-count> single-query aggregates, spread over five Fingerprints,
 * to the Store as fast as it can, while the test flushes concurrently.
 *
 * Usage: php push-concurrently.php <push-count>
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
$count = (int) ($argv[1] ?? 1);

for ($i = 0; $i < $count; $i++) {
    $store->push([new QueryAggregate(
        connection: 'mysql',
        hash: 'concurrent-'.($i % 5),
        sql: 'select * from `hound_items` where `sku` = ?',
        sample: null,
        count: 1,
        totalMs: 1.0,
        maxMs: 1.0,
        firstSeen: time(),
        lastSeen: time(),
    )]);
}

exit(0);
