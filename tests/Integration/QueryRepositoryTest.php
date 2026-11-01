<?php

use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Persistence\QueryRepository;

function repositoryAggregate(int $seenAt): QueryAggregate
{
    return new QueryAggregate(
        connection: 'mysql',
        hash: 'abc123',
        sql: 'select * from `orders` where `status` = ?',
        sample: 'encrypted-sample',
        count: 5,
        totalMs: 50.0,
        maxMs: 20.0,
        firstSeen: $seenAt,
        lastSeen: $seenAt,
    );
}

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('keeps first and last seen unchanged across repeated merges under a non-UTC timezone', function () {
    date_default_timezone_set('Asia/Riyadh');
    $repository = app(QueryRepository::class);

    $repository->merge([repositoryAggregate(1_790_000_000)]);
    $repository->merge([repositoryAggregate(1_790_000_000)]);
    $repository->merge([repositoryAggregate(1_790_000_000)]);

    [$stored] = $repository->all();

    expect($stored->firstSeen)->toBe(1_790_000_000)
        ->and($stored->lastSeen)->toBe(1_790_000_000)
        ->and($stored->count)->toBe(15);
});
