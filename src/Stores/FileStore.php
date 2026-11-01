<?php

namespace Foodics\DbHound\Stores;

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Contracts\Store;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Buffers aggregates as JSON lines in files under `{path}`, for apps without
 * Redis. Each process appends its pushes to a file of its own in
 * `{path}/buffer`, `{pid}.jsonl`, one aggregate per line: every Store the
 * process builds (one per request under PHP-FPM) appends to that one file.
 *
 * A snapshot first renames every buffer file into `{path}/flushing`, under
 * a name of its own: rename is atomic, so a push lands either before it
 * (and is in the snapshot) or after it (and goes to a fresh file for the
 * next one). A writer holds an exclusive lock on its file while appending
 * and checks, once it holds the lock, that the file is still in the buffer;
 * one that finds it rotated starts a new file instead. The lock also keeps
 * appends whole should two processes ever share a file (the same pid in two
 * containers sharing `{path}`). The snapshot takes that lock once after the
 * rename, so it waits out any append already under way, then reads the
 * files and deletes them. Files a snapshot left behind (its process died)
 * are read again by the next one. A file the snapshot cannot move or delete
 * fails it, rather than being skipped (or handed out again) every time.
 *
 * Only one snapshot runs at a time: it holds a lock on `{path}/snapshot.lock`
 * and one that finds it held takes nothing. The operating system releases
 * the lock of a process that dies.
 */
final class FileStore implements Store
{
    /** @var resource|null the file this process appends to */
    private $handle = null;

    /** Path of the file `$handle` was opened on. */
    private ?string $file = null;

    /** The process that opened `$handle`; a forked child must not share it. */
    private ?int $pid = null;

    public function __construct(
        private readonly string $path,
    ) {}

    public function push(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $lines = implode('', array_map(
            fn (QueryAggregate $aggregate) => json_encode([
                'connection' => $aggregate->connection,
                'hash' => $aggregate->hash,
                'sql' => $aggregate->sql,
                'sample' => $aggregate->sample,
                'sample_expires_at' => $aggregate->sampleExpiresAt,
                'count' => $aggregate->count,
                'total_ms' => $aggregate->totalMs,
                'max_ms' => $aggregate->maxMs,
                'first_seen' => $aggregate->firstSeen,
                'last_seen' => $aggregate->lastSeen,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n",
            $aggregates,
        ));

        $handle = $this->lockedBufferFile();

        try {
            $this->append($handle, $lines);
        } finally {
            // A failed append closes the file, which releases the lock.
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
            }
        }
    }

    public function snapshot(): array
    {
        $lock = $this->open($this->path.'/snapshot.lock', 'c');

        try {
            // Another snapshot is in progress; it takes everything buffered.
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return [];
            }

            $this->ensureDirectory($this->path.'/flushing');

            foreach ($this->files('buffer') as $file) {
                // Unique: a file of the same process may still sit there.
                $rotated = $this->path.'/flushing/'.basename($file, '.jsonl').'-'.bin2hex(random_bytes(8)).'.jsonl';

                if (! @rename($file, $rotated)) {
                    // A file the flush can never move would never be flushed.
                    clearstatcache(true, $file);

                    if (file_exists($file)) {
                        throw new RuntimeException("db-hound could not move its buffer file [{$file}] aside to flush it; check the permissions of [{$this->path}].");
                    }

                    continue;
                }

                $this->awaitAppend($rotated);
            }

            return $this->take($this->files('flushing'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Read without taking any lock, so capture never waits on it: a line
     * still being appended is left out, and files a snapshot moves meanwhile
     * may be missed or counted twice. Bytes are those of the buffered lines,
     * which repeat a Fingerprint's SQL once per push: what the buffer takes
     * on disk.
     */
    public function stats(): BufferStats
    {
        $members = [];
        $bytes = 0;
        $oldest = null;

        foreach ([...$this->files('buffer'), ...$this->files('flushing')] as $file) {
            foreach ($this->lines($file) as $line) {
                $aggregate = $this->decode($line);

                if ($aggregate === null) {
                    continue;
                }

                $members[$aggregate->key()] = true;
                $bytes += strlen($line);
                $oldest = $oldest === null ? $aggregate->firstSeen : min($oldest, $aggregate->firstSeen);
            }
        }

        return new BufferStats(count($members), $bytes, $oldest);
    }

    /**
     * This process's buffer file, opened and exclusively locked. A file a
     * snapshot rotated away (or deleted) since it was opened is replaced by
     * a new one.
     *
     * @return resource
     */
    private function lockedBufferFile()
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->handle === null || $this->pid !== getmypid()) {
                $this->openBufferFile();
            }

            /** @var resource $handle */
            $handle = $this->handle;

            if (! flock($handle, LOCK_EX)) {
                $this->closeBufferFile();

                throw new RuntimeException("db-hound could not lock its buffer file [{$this->file}].");
            }

            if ($this->isStillBuffered($handle)) {
                return $handle;
            }

            flock($handle, LOCK_UN);
            $this->closeBufferFile();
        }

        throw new RuntimeException("db-hound could not open a buffer file in [{$this->path}/buffer].");
    }

    private function openBufferFile(): void
    {
        $this->closeBufferFile();
        $this->ensureDirectory($this->path.'/buffer');

        // Named after the process, so every Store it builds (one per request
        // under PHP-FPM) appends to the same file. A snapshot gives the file
        // a name of its own as it moves it aside.
        $this->file = $this->path.'/buffer/'.getmypid().'.jsonl';
        $this->handle = $this->open($this->file, 'a');
        $this->pid = getmypid() ?: null;
    }

    private function closeBufferFile(): void
    {
        // A forked child shares its parent's handle; closing it is harmless.
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->file = null;
        $this->pid = null;
    }

    /**
     * Whether the open file is still the one at its buffer path, rather than
     * rotated into `flushing` or deleted by a snapshot.
     *
     * @param  resource  $handle
     */
    private function isStillBuffered($handle): bool
    {
        if ($this->file === null) {
            return false;
        }

        clearstatcache(true, $this->file);
        $atPath = @stat($this->file);
        $open = fstat($handle);

        return $atPath !== false && $open !== false
            && $atPath['ino'] === $open['ino'] && $atPath['dev'] === $open['dev'];
    }

    /**
     * Append whole lines, or nothing: a short write is cut back off, so the
     * file never holds half a line followed by the next push.
     *
     * @param  resource  $handle
     */
    private function append($handle, string $lines): void
    {
        $stat = fstat($handle);
        $size = $stat === false ? null : $stat['size'];

        try {
            // A failed write raises a notice, which the app may turn into
            // an exception: either way the cut below must still happen.
            if (@fwrite($handle, $lines) === strlen($lines) && @fflush($handle)) {
                return;
            }

            $failure = null;
        } catch (Throwable $failure) {
            // Handled below.
        }

        if ($size !== null) {
            @ftruncate($handle, $size);
        }

        $this->closeBufferFile();

        throw new RuntimeException("db-hound could not write to its buffer file in [{$this->path}/buffer].", previous: $failure);
    }

    /**
     * Wait until a writer that locked `$file` before it was rotated finishes
     * its append. Writers that lock it later see it rotated and leave it be.
     */
    private function awaitAppend(string $file): void
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Every aggregate in `$files`, merged per Fingerprint and connection,
     * deleting each file as it is taken. A file that can't be deleted is
     * left, with its aggregates, for a later snapshot (handing them out now
     * would count them again then); one that can't be deleted before any
     * other fails the snapshot, so the flush fails loudly rather than
     * leaving it behind every time.
     *
     * @param  list<string>  $files
     * @return list<QueryAggregate>
     */
    private function take(array $files): array
    {
        $now = Carbon::now()->getTimestamp();

        // Deleting a file it could not read would lose its aggregates.
        $read = array_map(fn (string $file) => $this->read($file, $now), $files);
        $taken = [];

        foreach ($files as $i => $file) {
            if (! @unlink($file)) {
                clearstatcache(true, $file);

                if (file_exists($file)) {
                    if ($taken === []) {
                        throw new RuntimeException("db-hound could not delete its Store file [{$file}] after reading it; check the permissions of [{$this->path}].");
                    }

                    break;
                }
            }

            $taken[] = $read[$i];
        }

        $aggregates = $this->merge($taken, $now);

        foreach ($aggregates as $aggregate) {
            $aggregate->expireSample($now);
        }

        return array_values($aggregates);
    }

    /**
     * The aggregates in `$file`, merged per Fingerprint and connection.
     * Lines that are not a whole aggregate (a writer died mid-append) are
     * skipped.
     *
     * @return array<string, QueryAggregate>
     */
    private function read(string $file, int $now): array
    {
        $aggregates = [];

        foreach ($this->lines($file, required: true) as $line) {
            $aggregate = $this->decode($line);

            if ($aggregate === null) {
                continue;
            }

            if (isset($aggregates[$aggregate->key()])) {
                $this->mergeInto($aggregates[$aggregate->key()], $aggregate, $now);
            } else {
                $aggregates[$aggregate->key()] = $aggregate;
            }
        }

        return $aggregates;
    }

    /**
     * Merge sets of aggregates keyed by Fingerprint and connection.
     *
     * @param  list<array<string, QueryAggregate>>  $sets
     * @return array<string, QueryAggregate>
     */
    private function merge(array $sets, int $now): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $key => $aggregate) {
                if (isset($merged[$key])) {
                    $this->mergeInto($merged[$key], $aggregate, $now);
                } else {
                    $merged[$key] = $aggregate;
                }
            }
        }

        return $merged;
    }

    /**
     * Merge `$other` into `$into`. Files are read in name order, not in the
     * order they were written, so of two live Samples the one written first
     * (the first to expire, as every Sample lives as long) is kept.
     */
    private function mergeInto(QueryAggregate $into, QueryAggregate $other, int $now): void
    {
        if ($into->hasLiveSample($now) && $other->hasLiveSample($now)
            && $other->sampleExpiresAt < $into->sampleExpiresAt) {
            $into->sample = $other->sample;
            $into->sampleExpiresAt = $other->sampleExpiresAt;
        }

        $into->merge($other, $now);
    }

    /**
     * The complete lines of `$file`; a last line without its newline is
     * still being written, or never will be. A file that can't be opened
     * has none, unless it is `required`.
     *
     * @return iterable<string>
     */
    private function lines(string $file, bool $required = false): iterable
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            if ($required) {
                throw new RuntimeException("db-hound could not read its Store file [{$file}].");
            }

            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (str_ends_with($line, "\n")) {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function decode(string $line): ?QueryAggregate
    {
        $fields = json_decode($line, true);

        if (! is_array($fields)
            || ! is_string($fields['connection'] ?? null)
            || ! is_string($fields['hash'] ?? null)
            || ! is_string($fields['sql'] ?? null)
            || ! is_int($fields['count'] ?? null)) {
            return null;
        }

        $number = fn (string $field): float => is_int($fields[$field] ?? null) || is_float($fields[$field] ?? null) ? (float) $fields[$field] : 0.0;
        $integer = fn (string $field): ?int => is_int($fields[$field] ?? null) ? $fields[$field] : null;

        return new QueryAggregate(
            connection: $fields['connection'],
            hash: $fields['hash'],
            sql: $fields['sql'],
            sample: is_string($fields['sample'] ?? null) ? $fields['sample'] : null,
            count: $fields['count'],
            totalMs: $number('total_ms'),
            maxMs: $number('max_ms'),
            firstSeen: $integer('first_seen') ?? 0,
            lastSeen: $integer('last_seen') ?? 0,
            sampleExpiresAt: $integer('sample_expires_at'),
        );
    }

    /**
     * The JSON lines files in `{path}/{directory}`.
     *
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $files = glob($this->path.'/'.$directory.'/*.jsonl');

        return $files === false ? [] : $files;
    }

    /**
     * Directories (and files) are created with the permissions the umask
     * allows, so processes of different users that share a group can share
     * them too.
     */
    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("db-hound could not create its Store directory [{$directory}].");
        }
    }

    /**
     * @return resource
     */
    private function open(string $file, string $mode)
    {
        $this->ensureDirectory(dirname($file));
        $handle = @fopen($file, $mode);

        if ($handle === false) {
            throw new RuntimeException("db-hound could not open [{$file}].");
        }

        return $handle;
    }
}
