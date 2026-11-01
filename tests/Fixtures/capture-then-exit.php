<?php

/*
 * Child process for CaptureTest: boots the package in a fresh application,
 * runs N queries (fewer than one batch) with capture enabled and exits
 * normally, so only the shutdown hook can push them to the Store.
 *
 * Usage: php capture-then-exit.php <query-count>
 */

use Foodics\DbHound\DbHoundServiceProvider;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create(options: [
    'extra' => [
        'providers' => [DbHoundServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);

IntegrationTestCase::configureServices($app['config']);
$app['config']->set('db-hound.enabled', true);

$count = (int) ($argv[1] ?? 1);

for ($i = 0; $i < $count; $i++) {
    DB::table('hound_items')->where('sku', "SKU-{$i}")->first();
}

exit(0);
