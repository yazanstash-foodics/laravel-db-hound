<?php

use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

/**
 * The scheduled events that run db-hound:prune.
 *
 * @return list<Event>
 */
function scheduledPrunes(): array
{
    app()->forgetInstance(Schedule::class);

    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event) => str_contains((string) $event->command, 'db-hound:prune'),
    ));
}

it('schedules db-hound:prune daily without overlapping runs', function () {
    $prunes = scheduledPrunes();

    expect($prunes)->toHaveCount(1)
        ->and($prunes[0]->expression)->toBe('0 0 * * *')
        ->and($prunes[0]->withoutOverlapping)->toBeTrue()
        ->and($prunes[0]->onOneServer)->toBeTrue()
        ->and($prunes[0]->expiresAt)->toBe(60);
});

it('runs the scheduled prune once the table exists, whether capture is on or off', function () {
    $prune = scheduledPrunes()[0];

    config()->set('db-hound.enabled', true);
    expect($prune->filtersPass(app()))->toBeFalse();

    (include __DIR__.'/../../database/migrations/create_db_hound_queries_table.php.stub')->up();

    config()->set('db-hound.enabled', false);
    expect($prune->filtersPass(app()))->toBeTrue();

    config()->set('db-hound.enabled', true);
    expect($prune->filtersPass(app()))->toBeTrue();

    Schema::drop(QueryRepository::TABLE);
});

it('skips the scheduled prune without failing the schedule when the database is unreachable', function () {
    $prune = scheduledPrunes()[0];

    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => '/nonexistent/db-hound/unreachable.sqlite',
        'prefix' => '',
    ]);
    config()->set('database.default', 'unreachable');

    expect($prune->filtersPass(app()))->toBeFalse();
});

it('lets the app schedule db-hound:prune itself', function () {
    config()->set('db-hound.prune.schedule', false);

    expect(scheduledPrunes())->toBeEmpty();
});
