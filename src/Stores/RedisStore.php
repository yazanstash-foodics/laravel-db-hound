<?php

namespace Foodics\DbHound\Stores;

use Closure;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Store;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Buffers every aggregate in one Redis hash, `{prefix}buffer`, with fields
 * `{attribute}:{connection}:{hash}` plus running totals (`_fingerprints`,
 * `_bytes`, `_oldest`) that make size and age introspection O(1). An
 * aggregate's Sample lives in its own hash that Redis expires with the
 * Sample, so sensitive values never outlive their TTL even if never flushed.
 *
 * Each push is a single pipelined round trip. Every aggregate is merged by a
 * small Lua script so counts, totals, max and first/last seen combine
 * atomically with whatever is already stored.
 *
 * A snapshot first RENAMEs the buffer to `{prefix}flushing`, atomically: a
 * push lands either before it (and is in the snapshot) or after it (and
 * starts a fresh buffer for the next one), never in between. The renamed
 * hash is then read in small chunks and deleted. A snapshot interrupted
 * before deleting it is picked up again by the next one, rather than being
 * overwritten.
 *
 * Only one snapshot runs at a time: it holds `{prefix}snapshot-lock`, which
 * it renews on each round trip, so two flushes (another server, a manual
 * `--force`) never both read the same `flushing` hash. A snapshot that finds
 * the lock held takes nothing. The lock of a snapshot whose process died
 * expires after SNAPSHOT_LOCK_MS, and the next snapshot recovers its hash.
 */
final class RedisStore implements Store
{
    /** Fields read per HSCAN call; keeps each reply small. */
    private const SCAN_COUNT = 1000;

    /** Samples taken per round trip. */
    private const SAMPLE_CHUNK = 200;

    /** How long a snapshot's lock outlives its last round trip. */
    private const SNAPSHOT_LOCK_MS = 60_000;

    /** Lua: fail unless KEYS[1] is the lock held by token ARGV[1], renewing it for ARGV[2] ms. */
    private const HOLD_LOCK = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return redis.error_reply('db-hound snapshot lock was lost')
end
redis.call('PEXPIRE', KEYS[1], ARGV[2])
LUA;

    private const MERGE_SCRIPT = <<<'LUA'
local m = ARGV[1]
local now = tonumber(ARGV[12])
local expires = tonumber(ARGV[11])
local bytes = 0
if redis.call('HSETNX', KEYS[1], 'sql:' .. m, ARGV[4]) == 1 then
    redis.call('HSET', KEYS[1], 'connection:' .. m, ARGV[2], 'hash:' .. m, ARGV[3])
    redis.call('HINCRBY', KEYS[1], '_fingerprints', 1)
    bytes = bytes + #ARGV[2] + #ARGV[3] + #ARGV[4]
end
if ARGV[5] ~= '' and expires and expires > now then
    local held = tonumber(redis.call('HGET', KEYS[2], 'expires_at'))
    if not held or held <= now then
        redis.call('HSET', KEYS[2], 'payload', ARGV[5], 'expires_at', ARGV[11])
        redis.call('EXPIRE', KEYS[2], expires - now)
        bytes = bytes + #ARGV[5]
    end
end
if bytes > 0 then
    redis.call('HINCRBY', KEYS[1], '_bytes', bytes)
end
redis.call('HINCRBY', KEYS[1], 'count:' .. m, ARGV[6])
redis.call('HINCRBYFLOAT', KEYS[1], 'total_ms:' .. m, ARGV[7])
local function keep(field, value, larger)
    local held = tonumber(redis.call('HGET', KEYS[1], field))
    if not held or (larger and tonumber(value) > held) or (not larger and tonumber(value) < held) then
        redis.call('HSET', KEYS[1], field, value)
    end
end
keep('max_ms:' .. m, ARGV[8], true)
keep('first_seen:' .. m, ARGV[9], false)
keep('last_seen:' .. m, ARGV[10], true)
keep('_oldest', ARGV[9], false)
return 1
LUA;

    /** Take the lock (KEYS[3]) or return 0; then set the buffer aside unless a leftover is there. */
    private const CLAIM_SCRIPT = <<<'LUA'
if not redis.call('SET', KEYS[3], ARGV[1], 'NX', 'PX', ARGV[2]) then
    return 0
end
if redis.call('EXISTS', KEYS[2]) == 0 and redis.call('EXISTS', KEYS[1]) == 1 then
    redis.call('RENAME', KEYS[1], KEYS[2])
end
return 1
LUA;

    private const SCAN_SCRIPT = self::HOLD_LOCK."\n".<<<'LUA'
return redis.call('HSCAN', KEYS[2], ARGV[3], 'COUNT', ARGV[4])
LUA;

    private const TAKE_SAMPLES_SCRIPT = self::HOLD_LOCK."\n".<<<'LUA'
local samples = {}
for i = 2, #KEYS do
    samples[i - 1] = redis.call('HMGET', KEYS[i], 'payload', 'expires_at')
    redis.call('DEL', KEYS[i])
end
return samples
LUA;

    private const RELEASE_SCRIPT = self::HOLD_LOCK."\n".<<<'LUA'
redis.call('DEL', KEYS[2], KEYS[1])
return 1
LUA;

    private const STATS_SCRIPT = <<<'LUA'
return {
    redis.call('HMGET', KEYS[1], '_fingerprints', '_bytes', '_oldest'),
    redis.call('HMGET', KEYS[2], '_fingerprints', '_bytes', '_oldest'),
}
LUA;

    private ?PhpRedisConnection $connection = null;

    /**
     * @param  PhpRedisConnection|Closure(): PhpRedisConnection  $redis  a connection, or a resolver called on first use (so an unreachable Redis is only contacted when needed)
     */
    public function __construct(
        private readonly PhpRedisConnection|Closure $redis,
        private readonly string $prefix = 'db-hound:',
    ) {}

    public function push(array $aggregates): void
    {
        $this->resettingOnFailure(fn () => $this->write($aggregates));
    }

    public function snapshot(): array
    {
        return $this->resettingOnFailure($this->take(...));
    }

    public function stats(): BufferStats
    {
        return $this->resettingOnFailure($this->measure(...));
    }

    /**
     * Run a Store operation; when it fails, drop the connection so the next
     * operation opens a fresh one. A call that timed out leaves the client
     * broken or, worse, out of step with Redis (its reply still on the way).
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function resettingOnFailure(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->forgetConnection();

            throw $e;
        }
    }

    /**
     * @param  list<QueryAggregate>  $aggregates
     */
    private function write(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $now = Carbon::now()->getTimestamp();

        $writes = array_map(fn (QueryAggregate $aggregate) => [
            [$this->bufferKey(), $this->sampleKey($aggregate->key())],
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
                $aggregate->sampleExpiresAt ?? '',
                $now,
            ],
        ], $aggregates);

        $redis = $this->connection();

        // One round trip per batch.
        $redis->pipeline(function (\Redis $pipe) use ($writes) {
            foreach ($writes as [$keys, $arguments]) {
                $pipe->eval(self::MERGE_SCRIPT, [...$keys, ...$arguments], count($keys));
            }
        });
    }

    /**
     * @return list<QueryAggregate>
     */
    private function take(): array
    {
        $token = bin2hex(random_bytes(16));

        $claimed = $this->evaluate(
            self::CLAIM_SCRIPT,
            [$this->bufferKey(), $this->flushingKey(), $this->lockKey()],
            [$token, self::SNAPSHOT_LOCK_MS],
        );

        // Another snapshot is in progress; it takes everything buffered.
        if ((int) $claimed !== 1) {
            return [];
        }

        $members = $this->readFlushing($token);
        $samples = $this->takeSamples($token, array_keys($members));
        $now = Carbon::now()->getTimestamp();
        $aggregates = [];

        foreach ($members as $member => $fields) {
            if (! isset($fields['connection'], $fields['hash'], $fields['sql'], $fields['count'])) {
                continue;
            }

            [$payload, $expiresAt] = $samples[$member] ?? [null, null];

            $aggregate = new QueryAggregate(
                connection: $fields['connection'],
                hash: $fields['hash'],
                sql: $fields['sql'],
                sample: is_string($payload) && $payload !== '' ? $payload : null,
                count: (int) $fields['count'],
                totalMs: (float) ($fields['total_ms'] ?? 0),
                maxMs: (float) ($fields['max_ms'] ?? 0),
                firstSeen: (int) ($fields['first_seen'] ?? 0),
                lastSeen: (int) ($fields['last_seen'] ?? 0),
                sampleExpiresAt: is_numeric($expiresAt) ? (int) $expiresAt : null,
            );

            $aggregate->expireSample($now);

            $aggregates[] = $aggregate;
        }

        $this->evaluate(self::RELEASE_SCRIPT, [$this->lockKey(), $this->flushingKey()], [$token, self::SNAPSHOT_LOCK_MS]);

        return $aggregates;
    }

    private function measure(): BufferStats
    {
        $result = $this->evaluate(self::STATS_SCRIPT, [$this->bufferKey(), $this->flushingKey()]);

        $fingerprints = 0;
        $bytes = 0;
        $oldest = null;

        foreach (is_array($result) ? $result : [] as $values) {
            [$count, $size, $seenAt] = array_pad(is_array($values) ? array_values($values) : [], 3, null);

            $fingerprints += (int) $count;
            $bytes += (int) $size;

            if (is_numeric($seenAt)) {
                $oldest = $oldest === null ? (int) $seenAt : min($oldest, (int) $seenAt);
            }
        }

        return new BufferStats($fingerprints, $bytes, $oldest);
    }

    /**
     * Every buffered field of the hash being flushed, grouped by member.
     *
     * @return array<string, array<string, string>>
     */
    private function readFlushing(string $token): array
    {
        $members = [];
        $cursor = '0';

        do {
            $reply = $this->evaluate(
                self::SCAN_SCRIPT,
                [$this->lockKey(), $this->flushingKey()],
                [$token, self::SNAPSHOT_LOCK_MS, $cursor, self::SCAN_COUNT],
            );

            if (! is_array($reply) || count($reply) !== 2 || ! is_array($reply[1])) {
                break;
            }

            $cursor = (string) $reply[0];
            $entries = array_values($reply[1]);

            for ($i = 0; $i + 1 < count($entries); $i += 2) {
                $field = (string) $entries[$i];
                $separator = strpos($field, ':');

                // Running totals (`_bytes`, ...) carry no member.
                if ($separator === false) {
                    continue;
                }

                $members[substr($field, $separator + 1)][substr($field, 0, $separator)] = (string) $entries[$i + 1];
            }
        } while ($cursor !== '0');

        return $members;
    }

    /**
     * Read and delete the Sample of each member.
     *
     * @param  list<string>  $members
     * @return array<string, array{0: mixed, 1: mixed}> payload and expiry, keyed by member
     */
    private function takeSamples(string $token, array $members): array
    {
        $samples = [];

        foreach (array_chunk($members, self::SAMPLE_CHUNK) as $chunk) {
            $reply = $this->evaluate(
                self::TAKE_SAMPLES_SCRIPT,
                [$this->lockKey(), ...array_map($this->sampleKey(...), $chunk)],
                [$token, self::SNAPSHOT_LOCK_MS],
            );

            foreach ($chunk as $i => $member) {
                $sample = is_array($reply) && is_array($reply[$i] ?? null) ? array_values($reply[$i]) : [];
                $samples[$member] = [$sample[0] ?? null, $sample[1] ?? null];
            }
        }

        return $samples;
    }

    /**
     * Run a Lua script.
     *
     * @param  list<string>  $keys
     * @param  list<string|int>  $arguments
     */
    private function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        $client = $this->connection()->client();
        $client->clearLastError();
        $result = $client->eval($script, [...$keys, ...$arguments], count($keys));

        // phpredis reports a script error as false rather than throwing.
        if ($result === false && $client->getLastError() !== null) {
            throw new RuntimeException('db-hound Redis script failed: '.$client->getLastError());
        }

        return $result;
    }

    private function connection(): PhpRedisConnection
    {
        return $this->connection ??= $this->redis instanceof Closure ? ($this->redis)() : $this->redis;
    }

    /**
     * Close the connection this Store opened so the next operation opens a
     * new one. A connection handed in directly is the caller's to manage.
     */
    private function forgetConnection(): void
    {
        if (! $this->redis instanceof Closure) {
            return;
        }

        try {
            $this->connection?->disconnect();
        } catch (Throwable) {
            // Already broken; nothing to close.
        }

        $this->connection = null;
    }

    private function bufferKey(): string
    {
        return $this->prefix.'buffer';
    }

    private function flushingKey(): string
    {
        return $this->prefix.'flushing';
    }

    private function lockKey(): string
    {
        return $this->prefix.'snapshot-lock';
    }

    private function sampleKey(string $member): string
    {
        return $this->prefix.'sample:'.$member;
    }
}
