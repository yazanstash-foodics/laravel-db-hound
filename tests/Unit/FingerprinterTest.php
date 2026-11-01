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
