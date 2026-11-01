<?php

namespace Foodics\DbHound\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
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

    /** A directory of its own for the file Store, removed after each test. */
    private ?string $fileStorePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freshDbHoundState();
    }

    protected function tearDown(): void
    {
        if ($this->fileStorePath !== null) {
            is_dir($this->fileStorePath)
                ? (new Filesystem)->deleteDirectory($this->fileStorePath)
                : @unlink($this->fileStorePath);
        }

        parent::tearDown();
    }

    /**
     * Recreate the package table and empty db-hound's Redis namespace, so each
     * test starts from nothing captured and nothing flushed.
     */
    protected function freshDbHoundState(): void
    {
        $this->freshDbHoundTable();

        // KEYS returns names with the client's own prefix; DEL would add it again.
        $redis = Redis::connection(config('db-hound.redis.connection'));
        $clientPrefix = (string) config('database.redis.options.prefix', '');
        $keys = array_map(
            fn (string $key) => str_starts_with($key, $clientPrefix) ? substr($key, strlen($clientPrefix)) : $key,
            $redis->keys(config('db-hound.redis.prefix').'*'),
        );

        if ($keys !== []) {
            $redis->del(...$keys);
        }
    }

    /**
     * Recreate an empty `db_hound_queries` table from the package migration.
     */
    protected function freshDbHoundTable(): void
    {
        Schema::dropIfExists('db_hound_queries');
        (include __DIR__.'/../database/migrations/create_db_hound_queries_table.php.stub')->up();
    }

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        self::configureServices($app['config']);

        $this->fileStorePath = sys_get_temp_dir().'/db-hound-tests-'.bin2hex(random_bytes(8));
        $app['config']->set('db-hound.file.path', $this->fileStorePath);
    }

    /**
     * Point an application's database and Redis config at the Docker services.
     * Shared with child processes spawned by tests (see tests/Fixtures).
     */
    public static function configureServices(Repository $config): void
    {
        $target = self::targetMysqlVersion();
        $portVariable = 'DB_HOUND_MYSQL'.str_replace('.', '', $target).'_PORT';

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('database.default', 'mysql');
        $config->set('database.connections.mysql', [
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

        $config->set('database.redis.client', env('DB_HOUND_REDIS_CLIENT', 'phpredis'));
        $config->set('database.redis.default', [
            'host' => env('DB_HOUND_REDIS_HOST', '127.0.0.1'),
            'port' => env('DB_HOUND_REDIS_PORT', '63790'),
            'database' => env('DB_HOUND_REDIS_DB', '0'),
        ]);
    }
}
