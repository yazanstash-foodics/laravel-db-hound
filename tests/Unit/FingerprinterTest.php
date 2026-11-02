<?php

use Foodics\DbHound\Capture\Fingerprinter;

it('gives queries that differ only in whitespace the same Fingerprint', function () {
    $fingerprinter = new Fingerprinter;

    $a = $fingerprinter->fingerprint("select *\n  from `orders`\twhere `branch_id` = ?");
    $b = $fingerprinter->fingerprint('  select * from `orders` where `branch_id` = ?  ');

    expect($a->sql)->toBe('select * from `orders` where `branch_id` = ?')
        ->and($a->hash)->toBe($b->hash);
});

it('gives different predicates different Fingerprints', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select * from `orders` where `branch_id` = ?')->hash)
        ->not->toBe($fingerprinter->fingerprint('select * from `orders` where `status` = ?')->hash);
});

it('collapses IN lists of any length to one marker', function () {
    $fingerprinter = new Fingerprinter;

    $inList = fn (int $length) => $fingerprinter->fingerprint(
        'select * from `orders` where `id` in ('.implode(', ', array_fill(0, $length, '?')).')'
    );

    expect($inList(1)->sql)->toBe('select * from `orders` where `id` in (?)')
        ->and($inList(3)->hash)->toBe($inList(1)->hash)
        ->and($inList(50)->hash)->toBe($inList(1)->hash);
});

it('replaces numeric and string literals with placeholders', function () {
    $fingerprinter = new Fingerprinter;

    $a = $fingerprinter->fingerprint("select * from `orders` where `branch_id` = 42 and `status` = 'open' and `total` > 9.5 limit 10");
    $b = $fingerprinter->fingerprint("select * from `orders` where `branch_id` = 7 and `status` = 'it''s \\'closed\\'' and `total` > -1e3 limit 25");
    $c = $fingerprinter->fingerprint('select * from `orders` where `branch_id` = 0x1F and `status` = "pending" and `total` > .5 limit 1');

    expect($a->sql)->toBe('select * from `orders` where `branch_id` = ? and `status` = ? and `total` > ? limit ?')
        ->and($b->hash)->toBe($a->hash)
        ->and($c->hash)->toBe($a->hash);
});

it('keeps digits that are part of identifiers', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select `t1`.`col_2` from t1 where t1.v2 = 3')->sql)
        ->toBe('select `t1`.`col_2` from t1 where t1.v2 = ?');
});

it('collapses IN lists of literals like IN lists of placeholders', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint("select * from `orders` where `status` in ('open', 'paid', 'void')")->hash)
        ->toBe($fingerprinter->fingerprint('select * from `orders` where `status` in (?)')->hash);
});

it('ignores comments', function () {
    $fingerprinter = new Fingerprinter;

    $a = $fingerprinter->fingerprint("/* controller:orders */ select * from `orders` -- trailing note\nwhere `branch_id` = ? # why\n and /*+ hint */ `status` = ?");

    expect($a->sql)->toBe('select * from `orders` where `branch_id` = ? and `status` = ?');
});

it('normalizes the case of SQL keywords but not of identifiers', function () {
    $fingerprinter = new Fingerprinter;

    $a = $fingerprinter->fingerprint('SELECT COUNT(*) FROM Orders WHERE `Status` IN (?, ?) AND Total IS NOT NULL ORDER BY created_at DESC');
    $b = $fingerprinter->fingerprint('select count(*) from Orders where `Status` in (?) and Total is not null order by created_at desc');

    expect($a->sql)->toBe('select count(*) from Orders where `Status` in (?) and Total is not null order by created_at desc')
        ->and($a->hash)->toBe($b->hash);
});

it('leaves comment and literal look-alikes inside quoted identifiers alone', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint("select `a -- b`, `it's 42` from `t`")->sql)
        ->toBe("select `a -- b`, `it's 42` from `t`");
});

it('gives queries with different IN columns or operators different Fingerprints', function () {
    $fingerprinter = new Fingerprinter;

    $hashes = array_map(fn (string $sql) => $fingerprinter->fingerprint($sql)->hash, [
        'select * from `orders` where `id` in (?, ?)',
        'select * from `orders` where `id` not in (?, ?)',
        'select * from `orders` where `branch_id` in (?, ?)',
        'select * from `orders` where `id` > ?',
    ]);

    expect(array_unique($hashes))->toHaveCount(4);
});

it('replaces very long string literals and comments, never yielding an empty Fingerprint', function (string $long) {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint("select * from `users` where `note` = {$long}")?->sql)
        ->toBe('select * from `users` where `note` = ?')
        ->and($fingerprinter->fingerprint("select * from `users` /* {$long} */ where `note` = ?")?->sql)
        ->toBe('select * from `users` where `note` = ?');
})->with([
    'single-quoted' => ["'".str_repeat('x', 200_000)."'"],
    'double-quoted' => ['"'.str_repeat('x', 200_000).'"'],
    'escape-heavy' => ["'".str_repeat("a\\'b''", 50_000)."'"],
]);

it('collapses a 50,000-element inline IN list', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select * from `users` where `id` in ('.implode(', ', range(1, 50_000)).')')?->sql)
        ->toBe('select * from `users` where `id` in (?)');
});

it('gives no Fingerprint, rather than an empty one, when the regex engine fails', function () {
    $fingerprinter = new Fingerprinter;
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $fingerprint = $fingerprinter->fingerprint("select * from `users` where `note` = 'secret'");
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($fingerprint)->toBeNull();
});

it('replaces binary and bit-value literals', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint("select * from `t` where `a` = 0b1011 and `b` = b'0101' and `c` = B'1'")?->sql)
        ->toBe('select * from `t` where `a` = ? and `b` = ? and `c` = ?');
});

it('normalizes whitespace around comparison operators', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select * from t where a=1 and b<>2 and c>=3 and d<=>4 and e!=5 and f<6')?->sql)
        ->toBe('select * from t where a = ? and b <> ? and c >= ? and d <=> ? and e != ? and f < ?')
        ->and($fingerprinter->fingerprint('select a->>? from t where b >> 2')?->sql)
        ->toBe('select a->>? from t where b >> ?');
});

it('replaces negative literals whatever the spacing', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select * from t where a in (1,  -3) and b =   - 4 and c > (-5)')?->sql)
        ->toBe('select * from t where a in (?) and b = ? and c > (?)');
});

it('collapses tuple IN lists and multi-row VALUES of any length', function () {
    $fingerprinter = new Fingerprinter;

    expect($fingerprinter->fingerprint('select * from t where (a, b) in ((1, 2), (3, 4), (5, 6))')?->sql)
        ->toBe('select * from t where (a, b) in ((?, ?))')
        ->and($fingerprinter->fingerprint('insert into `t` (`a`, `b`) values (?, ?), (?, ?), (?, ?)')?->sql)
        ->toBe('insert into `t` (`a`, `b`) values (?, ?)')
        ->and($fingerprinter->fingerprint('select * from t where (a, b) in ((?, ?, ?))')?->hash)
        ->not->toBe($fingerprinter->fingerprint('select * from t where (a, b) in ((?, ?))')?->hash);
});

it('gives no Fingerprint when a quote is left unpaired, rather than leak what follows it', function (string $sql) {
    expect((new Fingerprinter)->fingerprint($sql))->toBeNull();
})->with([
    // Valid under NO_BACKSLASH_ESCAPES, where the first literal is `C:\`.
    'backslash-terminated literal' => ["select * from users where email = 'C:\\' and token = 'secret-token'"],
    'two backslash-terminated literals' => ["select * from users where a = 'x\\', b = 'secret', c = 'y\\', d = 'z'"],
    'unterminated literal' => ["select * from users where token = 'secret"],
    'unterminated double-quoted literal' => ['select * from users where token = "secret'],
]);

it('normalizes the case of keywords, functions and interval units', function () {
    $fingerprinter = new Fingerprinter;

    $pairs = [
        ['select * from t where created_at > now() - INTERVAL 5 DAY', 'select * from t where created_at > now() - interval 5 day'],
        ['insert into t (a) values (1) ON DUPLICATE KEY UPDATE a = VALUES(a)', 'insert into t (a) values (1) on duplicate key update a = values(a)'],
        ["select CONCAT(a, b), DATE_FORMAT(c, '%Y'), JSON_EXTRACT(d, '$.x') from t", "select concat(a, b), date_format(c, '%Y'), json_extract(d, '$.x') from t"],
        ["select * from t where a LIKE 'x' ESCAPE '!' COLLATE utf8mb4_bin", "select * from t where a like 'x' escape '!' collate utf8mb4_bin"],
        ['select a, SUM(b) OVER (PARTITION BY c) from t GROUP BY a WITH ROLLUP', 'select a, sum(b) over (partition by c) from t group by a with rollup'],
        ['select BINARY a, DATE_ADD(b, INTERVAL 1 HOUR), CURRENT_TIMESTAMP from t', 'select binary a, date_add(b, interval 1 hour), current_timestamp from t'],
    ];

    foreach ($pairs as [$upper, $lower]) {
        expect($fingerprinter->fingerprint($upper)?->hash)->toBe($fingerprinter->fingerprint($lower)?->hash, $upper);
    }
});
