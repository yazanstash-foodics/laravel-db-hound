<?php

namespace Foodics\DbHound\Stores;

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Store;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Keeps one Redis hash per aggregate plus a set indexing them.
 *
 * Each push is a single pipelined round trip. Every aggregate is merged by a
 * small Lua script so counts, totals, max and first/last seen combine
 * atomically with whatever is already stored.
 */
final class RedisStore implements Store
{
    private const MERGE_SCRIPT = <<<'LUA'
redis.call('SADD', KEYS[2], ARGV[1])
redis.call('HSETNX', KEYS[1], 'connection', ARGV[2])
redis.call('HSETNX', KEYS[1], 'hash', ARGV[3])
redis.call('HSETNX', KEYS[1], 'sql', ARGV[4])
if ARGV[5] ~= '' then
    redis.call('HSETNX', KEYS[1], 'sample', ARGV[5])
end
redis.call('HINCRBY', KEYS[1], 'count', ARGV[6])
redis.call('HINCRBYFLOAT', KEYS[1], 'total_ms', ARGV[7])
local max = tonumber(redis.call('HGET', KEYS[1], 'max_ms'))
if not max or tonumber(ARGV[8]) > max then
    redis.call('HSET', KEYS[1], 'max_ms', ARGV[8])
end
local first = tonumber(redis.call('HGET', KEYS[1], 'first_seen'))
if not first or tonumber(ARGV[9]) < first then
    redis.call('HSET', KEYS[1], 'first_seen', ARGV[9])
end
local last = tonumber(redis.call('HGET', KEYS[1], 'last_seen'))
if not last or tonumber(ARGV[10]) > last then
    redis.call('HSET', KEYS[1], 'last_seen', ARGV[10])
end
return 1
LUA;

    public function __construct(
        private readonly Connection $redis,
        private readonly string $prefix = 'db-hound:',
    ) {}

    public function push(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $writes = array_map(fn (QueryAggregate $aggregate) => [
            [$this->aggregateKey($aggregate->key()), $this->indexKey()],
            [
                $aggregate->key(),
                $aggregate->connection,
                $aggregate->hash,
                $aggregate->sql,
                $aggregate->sample ?? '',
                $aggregate->count,
                $aggregate->totalMs,
                $aggregate->maxMs,
                $aggregate->firstSeen,
                $aggregate->lastSeen,
            ],
        ], $aggregates);

        // One round trip per batch; the two clients spell EVAL differently.
        if ($this->redis instanceof PhpRedisConnection) {
            $this->redis->pipeline(function (\Redis $pipe) use ($writes) {
                foreach ($writes as [$keys, $arguments]) {
                    $pipe->eval(self::MERGE_SCRIPT, [...$keys, ...$arguments], count($keys));
                }
            });

            return;
        }

        $this->redis->command('pipeline', [function ($pipe) use ($writes) {
            foreach ($writes as [$keys, $arguments]) {
                $pipe->eval(self::MERGE_SCRIPT, count($keys), ...$keys, ...$arguments);
            }
        }]);
    }

    public function snapshot(): array
    {
        /** @var list<string> $members */
        $members = $this->redis->command('smembers', [$this->indexKey()]) ?: [];

        $aggregates = [];

        foreach ($members as $member) {
            $key = $this->aggregateKey($member);

            /** @var array<string, string> $fields */
            $fields = $this->redis->command('hgetall', [$key]) ?: [];

            $this->redis->command('del', [$key]);
            $this->redis->command('srem', [$this->indexKey(), $member]);

            if (! isset($fields['connection'], $fields['hash'], $fields['sql'], $fields['count'])) {
                continue;
            }

            $aggregates[] = new QueryAggregate(
                connection: $fields['connection'],
                hash: $fields['hash'],
                sql: $fields['sql'],
                sample: ($fields['sample'] ?? '') !== '' ? $fields['sample'] : null,
                count: (int) $fields['count'],
                totalMs: (float) ($fields['total_ms'] ?? 0),
                maxMs: (float) ($fields['max_ms'] ?? 0),
                firstSeen: (int) ($fields['first_seen'] ?? 0),
                lastSeen: (int) ($fields['last_seen'] ?? 0),
            );
        }

        return $aggregates;
    }

    private function indexKey(): string
    {
        return $this->prefix.'queries';
    }

    private function aggregateKey(string $member): string
    {
        return $this->prefix.'query:'.$member;
    }
}
