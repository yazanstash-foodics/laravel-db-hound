<?php

use Foodics\DbHound\Analysis\Qualification;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Tests\IntegrationTestCase;
use Foodics\DbHound\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

uses(TestCase::class)->in('Unit');
uses(IntegrationTestCase::class)->group('integration')->in('Integration');

/**
 * Push the Recorder's in-memory batch, then return every value db-hound
 * holds in Redis, keyed by Redis key.
 *
 * @return array<string, string>
 */
function pushedRedisValues(): array
{
    $enabled = config('db-hound.enabled');
    config()->set('db-hound.enabled', false);
    app(Recorder::class)->push();
    config()->set('db-hound.enabled', $enabled);

    $redis = Redis::connection(config('db-hound.redis.connection'));
    $clientPrefix = (string) config('database.redis.options.prefix', '');
    $values = [];

    foreach ($redis->keys(config('db-hound.redis.prefix').'*') as $key) {
        $key = str_starts_with($key, $clientPrefix) ? substr($key, strlen($clientPrefix)) : $key;
        // phpredis reports types as integers, predis as names.
        $type = $redis->type($key);
        $type = is_int($type) ? [1 => 'string', 2 => 'set', 5 => 'hash'][$type] ?? 'other' : (string) $type;

        $values[$key] = match ($type) {
            'hash' => implode("\n", $redis->hgetall($key)),
            'set' => implode("\n", $redis->smembers($key)),
            default => (string) $redis->get($key),
        };
    }

    return $values;
}

/**
 * A Qualification that lets every Fingerprint and table through, for tests
 * about what the Analyzer suggests rather than what it skips.
 */
function everyFingerprint(): Qualification
{
    return new Qualification(minCount: 0, minAvgMs: 0.0, minMaxMs: 0.0, minTableRows: 0);
}

/*
 * Every Store driver, for the shared Store contract: each test using it runs
 * once per driver, after useStore() switched to it.
 */
dataset('stores', ['redis', 'file']);

/**
 * Switch db-hound to the `$driver` Store, dropping any Store and Recorder
 * already built for the previous one. Other drivers get a Redis connection
 * that refuses every connection, so a test passes only when the selected
 * driver alone holds the data.
 */
function useStore(string $driver): void
{
    config()->set('db-hound.store', $driver);

    if ($driver !== 'redis') {
        config()->set('database.redis.db-hound-unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
        config()->set('db-hound.redis.connection', 'db-hound-unreachable');
    }

    app()->forgetInstance(Store::class);
    app()->forgetInstance(Recorder::class);
}

/**
 * The environment for a child process (see tests/Fixtures) that must use the
 * same MySQL server and Store as the test.
 *
 * @return array<string, string>
 */
function childProcessEnv(): array
{
    return [
        'DB_HOUND_MYSQL' => IntegrationTestCase::targetMysqlVersion(),
        'DB_HOUND_STORE' => (string) config('db-hound.store'),
        'DB_HOUND_FILE_PATH' => (string) config('db-hound.file.path'),
    ] + getenv();
}

/**
 * The README is the contract with engineers and their local coding agents:
 * these tests keep it in step with what the package actually publishes and
 * writes.
 */
function readme(): string
{
    return (string) file_get_contents(__DIR__.'/../README.md');
}

/**
 * The part of the README under `$heading`, up to the next `## ` heading.
 */
function readmeSection(string $heading): string
{
    $start = strpos(readme(), "\n## {$heading}\n");
    expect($start)->not->toBeFalse("README has no \"## {$heading}\" section");

    $end = strpos(readme(), "\n## ", (int) $start + 1);

    return substr(readme(), (int) $start, $end === false ? null : $end - (int) $start);
}
