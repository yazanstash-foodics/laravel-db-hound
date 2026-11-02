<?php

use Foodics\DbHound\Capture\CapturePolicy;
use Foodics\DbHound\Capture\Fingerprinter;

/**
 * Whether the policy keeps `$sql`, judged on its Fingerprint as the Recorder does.
 */
function admitsQuery(CapturePolicy $policy, string $sql): bool
{
    $fingerprint = (new Fingerprinter)->fingerprint($sql);

    expect($fingerprint)->not->toBeNull();

    return $policy->admitsTables($fingerprint->sql);
}

it('finds tables reached through a bare JOIN', function (string $sql) {
    expect(admitsQuery(new CapturePolicy(deniedTables: ['orders']), $sql))->toBeFalse()
        ->and(admitsQuery(new CapturePolicy(allowedTables: ['orders']), $sql))->toBeTrue();
})->with([
    'join' => ['select * from users join orders on orders.user_id = users.id'],
    'backticked join' => ['select * from `users` join `orders` on `orders`.`user_id` = `users`.`id`'],
    'straight_join' => ['select * from users straight_join orders'],
    'join on a new line' => ["select * from users\njoin orders on 1"],
    'update join' => ['update users join orders on orders.user_id = users.id set users.x = 1'],
    'aliased, then join' => ['select * from users u join orders o on o.user_id = u.id'],
]);

it('finds the tables of DDL and TRUNCATE statements', function (string $sql) {
    expect(admitsQuery(new CapturePolicy(deniedTables: ['orders']), $sql))->toBeFalse()
        ->and(admitsQuery(new CapturePolicy, str_replace('orders', 'db_hound_queries', $sql)))->toBeFalse();
})->with([
    'create table' => ['create table `orders` (`id` bigint unsigned not null auto_increment primary key)'],
    'create temporary table if not exists' => ['create temporary table if not exists orders (id int)'],
    'alter table' => ['alter table `orders` add index `orders_status_index`(`status`)'],
    'drop table if exists' => ['drop table if exists `orders`'],
    'drop tables' => ['drop table users, orders'],
    'truncate table' => ['truncate table orders'],
    'truncate' => ['truncate `orders`'],
    'rename table' => ['rename table orders to orders_old'],
    'create index' => ['create unique index orders_status on orders (status)'],
    'drop index' => ['drop index orders_status on `orders`'],
]);

it('finds tables behind modifiers, index hints, partitions and parentheses', function (string $sql) {
    expect(admitsQuery(new CapturePolicy(deniedTables: ['users']), $sql))->toBeFalse();
})->with([
    'comma join after an index hint' => ['select * from orders force index (idx), users'],
    'comma join after a multi-column hint' => ['select * from orders use index for join (a, b), users u'],
    'comma join after a partition' => ['select * from orders partition (p0, p1), users'],
    'parenthesized join' => ['select * from orders o inner join (users u inner join x on 1) on 1'],
    'parenthesized table' => ['select * from (`users`)'],
    'update with modifiers' => ['update low_priority ignore users set a = 1'],
    'insert without into' => ['insert users values (1)'],
    'insert with modifiers' => ['insert high_priority ignore into users (a) values (1)'],
    'delete with modifiers' => ['delete low_priority quick ignore from users where id = 1'],
]);

it('does not mistake a derived table or a function for a table', function () {
    $policy = new CapturePolicy(allowedTables: ['s*']);

    expect(admitsQuery($policy, 'select * from (select 1) as t'))->toBeFalse()
        ->and(admitsQuery($policy, 'select truncate (price, 2) from orders'))->toBeFalse();
});

it('keeps nothing when the regex engine cannot read the tables', function () {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $admitted = (new CapturePolicy)->admitsTables('select * from `db_hound_queries`');
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($admitted)->toBeFalse();
});

it('does not mistake the column after ON DUPLICATE KEY UPDATE for a table', function () {
    $policy = new CapturePolicy(deniedTables: ['status']);

    expect(admitsQuery($policy, 'insert into orders (status) values (1) on duplicate key update status = values(status)'))->toBeTrue();
});
