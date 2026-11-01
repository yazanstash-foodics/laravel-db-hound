<?php

namespace Foodics\DbHound\Tests;

/**
 * Base case for tests that need real MySQL and Redis (see docker-compose.yml).
 * Every connection setting can be overridden through env vars.
 */
class IntegrationTestCase extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '33080'),
            'database' => env('DB_DATABASE', 'db_hound'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        config()->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        config()->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '63790'),
            'database' => env('REDIS_DB', '0'),
        ]);
    }
}
