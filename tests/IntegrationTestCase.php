<?php

namespace Foodics\DbHound\Tests;

use InvalidArgumentException;

/**
 * Base case for tests that need real MySQL and Redis (see docker-compose.yml).
 *
 * DB_HOUND_MYSQL picks the target server ("8.0" by default, or "8.4"). That sets
 * the port and the version the smoke test expects. Every setting reads a
 * DB_HOUND_* env var, never Laravel's generic DB_* / REDIS_* names. A developer's
 * shell config can't redirect the suite's destructive schema changes to some
 * other database:
 *
 *   DB_HOUND_MYSQL80_PORT / DB_HOUND_MYSQL84_PORT / DB_HOUND_REDIS_PORT (shared with docker-compose.yml)
 *   DB_HOUND_DB_HOST, DB_HOUND_DB_DATABASE, DB_HOUND_DB_USERNAME, DB_HOUND_DB_PASSWORD
 *   DB_HOUND_REDIS_HOST, DB_HOUND_REDIS_DB, DB_HOUND_REDIS_CLIENT
 */
class IntegrationTestCase extends TestCase
{
    private const DEFAULT_MYSQL_PORTS = [
        '8.0' => '33080',
        '8.4' => '33084',
    ];

    public static function targetMysqlVersion(): string
    {
        $target = (string) env('DB_HOUND_MYSQL', '8.0');

        if (! array_key_exists($target, self::DEFAULT_MYSQL_PORTS)) {
            throw new InvalidArgumentException(
                'DB_HOUND_MYSQL must be one of ['.implode(', ', array_keys(self::DEFAULT_MYSQL_PORTS))."], got [{$target}]."
            );
        }

        return $target;
    }

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $target = self::targetMysqlVersion();
        $portVariable = 'DB_HOUND_MYSQL'.str_replace('.', '', $target).'_PORT';

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOUND_DB_HOST', '127.0.0.1'),
            'port' => env($portVariable, self::DEFAULT_MYSQL_PORTS[$target]),
            'database' => env('DB_HOUND_DB_DATABASE', 'db_hound'),
            'username' => env('DB_HOUND_DB_USERNAME', 'root'),
            'password' => env('DB_HOUND_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        config()->set('database.redis.client', env('DB_HOUND_REDIS_CLIENT', 'phpredis'));
        config()->set('database.redis.default', [
            'host' => env('DB_HOUND_REDIS_HOST', '127.0.0.1'),
            'port' => env('DB_HOUND_REDIS_PORT', '63790'),
            'database' => env('DB_HOUND_REDIS_DB', '0'),
        ]);
    }
}
