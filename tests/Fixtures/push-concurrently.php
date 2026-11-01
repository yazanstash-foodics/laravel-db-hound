<?php

/*
 * Child process for the Store contract: boots the package in a fresh
 * application, prints "ready", then pushes <push-count> single-query
 * aggregates, spread over five Fingerprints named <name>-0 to <name>-4, to
 * the Store (the one DB_HOUND_STORE selects) as fast as it can, while the
 * test flushes or pushes concurrently. With <pause-us>, it waits that many
 * microseconds between pushes, so a fast Store's pushes still span several
 * flushes.
 *
 * Usage: php push-concurrently.php <push-count> [name] [pause-us]
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
$name = $argv[2] ?? 'concurrent';
$pause = (int) ($argv[3] ?? 0);

fwrite(STDOUT, "ready\n");

for ($i = 0; $i < $count; $i++) {
    $store->push([new QueryAggregate(
        connection: 'mysql',
        hash: $name.'-'.($i % 5),
        sql: 'select * from `hound_items` where `sku` = ?',
        sample: null,
        count: 1,
        totalMs: 1.0,
        maxMs: 1.0,
        firstSeen: time(),
        lastSeen: time(),
    )]);

    if ($pause > 0) {
        usleep($pause);
    }
}

exit(0);
