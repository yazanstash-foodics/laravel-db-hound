<?php

use Foodics\DbHound\Capture\Recorder;
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
