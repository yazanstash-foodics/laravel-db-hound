<?php

/*
 * What only the file Store does; the Store contract covers the rest.
 */

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Capture\Recorder;
use Foodics\DbHound\Contracts\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    useStore('file');
});

afterEach(function () {
    config()->set('db-hound.enabled', false);
    Schema::dropIfExists('hound_items');
});

/**
 * An aggregate of Fingerprint `$hash`, seen `$count` times.
 */
function fileAggregate(string $hash, int $count = 1): QueryAggregate
{
    return new QueryAggregate('mysql', $hash, 'select * from `hound_items` where `sku` = ?', null, $count, 1.0, 1.0, 1_790_000_000, 1_790_000_000);
}

/**
 * Every file under the file Store's directory, keyed by path.
 *
 * @return array<string, string>
 */
function fileStoreContents(): array
{
    return collect(File::allFiles(config('db-hound.file.path')))
        ->mapWithKeys(fn (SplFileInfo $file) => [$file->getPathname() => (string) file_get_contents($file->getPathname())])
        ->all();
}

it('recovers files a snapshot set aside but died before reading', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a', count: 3)]);

    // A flush renamed the buffer files aside, then its process was killed.
    $path = config('db-hound.file.path');
    File::ensureDirectoryExists($path.'/flushing');
    foreach (File::glob($path.'/buffer/*.jsonl') as $file) {
        File::move($file, $path.'/flushing/'.basename($file));
    }

    $store->push([fileAggregate('a', count: 2), fileAggregate('b', count: 1)]);

    expect($store->stats()->fingerprints)->toBe(2);

    $snapshot = collect($store->snapshot())->keyBy('hash');

    expect($snapshot['a']->count)->toBe(5)
        ->and($snapshot['b']->count)->toBe(1)
        ->and($store->stats()->fingerprints)->toBe(0)
        ->and(fileStoreContents())->each->toBe('');
});

it('skips a line its writer died in the middle of and keeps the rest', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a', count: 3)]);

    // Another process wrote one whole line, then died halfway through the next.
    $path = config('db-hound.file.path');
    $whole = '{"connection":"mysql","hash":"b","sql":"select 1","sample":null,"sample_expires_at":null,"count":2,"total_ms":2.0,"max_ms":1.0,"first_seen":1790000000,"last_seen":1790000000}';
    file_put_contents($path.'/buffer/dead-writer.jsonl', $whole."\n".'{"connection":"mysql","hash":"a","sql":"sel');

    expect($store->stats()->fingerprints)->toBe(2);

    $snapshot = collect($store->snapshot())->keyBy('hash');

    expect($snapshot->keys()->all())->toEqualCanonicalizing(['a', 'b'])
        ->and($snapshot['a']->count)->toBe(3)
        ->and($snapshot['b']->count)->toBe(2);
});

it('keeps a file it cannot read for a later snapshot instead of deleting it', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a', count: 3)]);

    // Written by another user, say, with permissions the flush can't read.
    $path = config('db-hound.file.path');
    file_put_contents($path.'/buffer/other-user.jsonl', json_encode([
        'connection' => 'mysql', 'hash' => 'b', 'sql' => 'select 1', 'count' => 2, 'first_seen' => 1_790_000_000,
    ])."\n");
    chmod($path.'/buffer/other-user.jsonl', 0);

    try {
        expect(fn () => $store->snapshot())->toThrow(RuntimeException::class, 'other-user-');
    } finally {
        foreach (File::glob($path.'/flushing/other-user-*.jsonl') as $file) {
            chmod($file, 0644);
        }
    }

    $snapshot = collect($store->snapshot())->keyBy('hash');

    expect($snapshot->keys()->all())->toEqualCanonicalizing(['a', 'b'])
        ->and($snapshot['b']->count)->toBe(2);
})->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root reads every file');

it('keeps bindings out of its files', function () {
    Schema::dropIfExists('hound_items');
    Schema::create('hound_items', function (Blueprint $table) {
        $table->id();
        $table->string('sku');
    });
    config()->set('db-hound.enabled', true);

    for ($i = 0; $i < 50; $i++) {
        DB::table('hound_items')->where('sku', 'secret-sku-value')->first();
    }

    $contents = implode("\n", fileStoreContents());

    expect($contents)->toContain('select * from `hound_items` where `sku` = ? limit ?')
        ->not->toContain('secret-sku-value');
});

it('rejects a store config naming no Store driver', function () {
    config()->set('db-hound.store', 'memcached');
    app()->forgetInstance(Store::class);
    app()->forgetInstance(Recorder::class);

    expect(fn () => app(Store::class))->toThrow(InvalidArgumentException::class, 'memcached');
});

it('appends every request a process serves to one buffer file', function () {
    // PHP-FPM builds a fresh application, and so a fresh Store, per request.
    for ($request = 0; $request < 20; $request++) {
        app()->forgetInstance(Store::class);
        app(Store::class)->push([fileAggregate('a')]);
    }

    expect(File::glob(config('db-hound.file.path').'/buffer/*.jsonl'))->toHaveCount(1)
        ->and(app(Store::class)->snapshot()[0]->count)->toBe(20);
});

it('fails a snapshot that cannot move a buffer file aside, keeping the file for a later one', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a', count: 3)]);

    // Created by another user, say, so the flush may not rename in it.
    $buffer = config('db-hound.file.path').'/buffer';
    chmod($buffer, 0555);

    try {
        expect(fn () => $store->snapshot())->toThrow(RuntimeException::class, $buffer);
    } finally {
        chmod($buffer, 0755);
    }

    expect($store->stats()->fingerprints)->toBe(1)
        ->and($store->snapshot()[0]->count)->toBe(3);
})->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root renames anywhere');

it('hands out a file it cannot delete only once it can, failing the snapshot until then', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a', count: 3)]);

    // A flush moved the file aside, then lost the right to delete it.
    $path = config('db-hound.file.path');
    File::ensureDirectoryExists($path.'/flushing');
    foreach (File::glob($path.'/buffer/*.jsonl') as $file) {
        File::move($file, $path.'/flushing/'.basename($file));
    }
    chmod($path.'/flushing', 0555);

    try {
        expect(fn () => $store->snapshot())->toThrow(RuntimeException::class, $path.'/flushing')
            ->and(fn () => $store->snapshot())->toThrow(RuntimeException::class, $path.'/flushing');
    } finally {
        chmod($path.'/flushing', 0755);
    }

    expect($store->snapshot()[0]->count)->toBe(3)
        ->and($store->snapshot())->toBe([]);
})->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root deletes anything');

it('keeps the first live Sample of a Fingerprint whichever process wrote it', function () {
    $this->freezeTime();
    $now = now()->getTimestamp();
    $sampled = fn (string $sample, int $expiresAt) => new QueryAggregate('mysql', 'a', 'select * from `hound_items` where `sku` = ?', $sample, 1, 1.0, 1.0, $now, $now, $expiresAt);

    app(Store::class)->push([$sampled('first', $now + 600)]);

    // Another process captured the same Fingerprint later; its file sorts first.
    $path = config('db-hound.file.path');
    file_put_contents($path.'/buffer/0-other-process.jsonl', json_encode([
        'connection' => 'mysql', 'hash' => 'a', 'sql' => 'select * from `hound_items` where `sku` = ?',
        'sample' => 'second', 'sample_expires_at' => $now + 900, 'count' => 1, 'first_seen' => $now, 'last_seen' => $now,
    ])."\n");

    $snapshot = app(Store::class)->snapshot();

    expect($snapshot[0]->sample)->toBe('first')
        ->and($snapshot[0]->sampleExpiresAt)->toBe($now + 600)
        ->and($snapshot[0]->count)->toBe(2);
});

it('cuts a push it could only partly write back off, so the next push is whole', function () {
    $store = app(Store::class);
    $store->push([fileAggregate('a')]);

    // The disk fills up partway through the next push.
    [$buffer] = File::glob(config('db-hound.file.path').'/buffer/*.jsonl');
    $large = new QueryAggregate('mysql', 'large', 'select '.str_repeat('`column`, ', 500).'1', null, 1, 1.0, 1.0, 1_790_000_000, 1_790_000_000);
    pcntl_signal(SIGXFSZ, SIG_IGN);
    posix_setrlimit(POSIX_RLIMIT_FSIZE, filesize($buffer) + 100, POSIX_RLIMIT_INFINITY);

    try {
        expect(fn () => $store->push([$large]))->toThrow(RuntimeException::class);
    } finally {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
        pcntl_signal(SIGXFSZ, SIG_DFL);
    }

    $store->push([fileAggregate('b')]);
    $store->push([fileAggregate('c')]);

    expect(collect($store->snapshot())->pluck('hash')->all())->toEqualCanonicalizing(['a', 'b', 'c']);
})->skip(fn () => ! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal'), 'needs the posix and pcntl extensions');

it('leaves the permissions of the directories and files it creates to the umask', function () {
    // The app's web and cron users share a group, so it allows group writes.
    $umask = umask(0002);

    try {
        app(Store::class)->push([fileAggregate('a')]);
        app(Store::class)->snapshot();
        app(Store::class)->push([fileAggregate('b')]);
    } finally {
        umask($umask);
    }

    $path = config('db-hound.file.path');
    $mode = fn (string $file) => fileperms($file) & 0777;

    expect($mode($path.'/buffer'))->toBe(0775)
        ->and($mode($path.'/flushing'))->toBe(0775)
        ->and($mode(File::glob($path.'/buffer/*.jsonl')[0]))->toBe(0664);
});
