<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled events that run db-hound:flush.
 *
 * @return list<Event>
 */
function scheduledFlushes(): array
{
    app()->forgetInstance(Schedule::class);

    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event) => str_contains((string) $event->command, 'db-hound:flush'),
    ));
}

it('schedules db-hound:flush every minute, running while capture is enabled', function () {
    $flushes = scheduledFlushes();

    expect($flushes)->toHaveCount(1)
        ->and($flushes[0]->expression)->toBe('* * * * *')
        ->and($flushes[0]->withoutOverlapping)->toBeTrue()
        ->and($flushes[0]->onOneServer)->toBeTrue()
        // A flush that dies mid-run must not block the next ones for a day.
        ->and($flushes[0]->expiresAt)->toBe(10);

    config()->set('db-hound.enabled', false);
    expect($flushes[0]->filtersPass(app()))->toBeFalse();

    config()->set('db-hound.enabled', true);
    expect($flushes[0]->filtersPass(app()))->toBeTrue();
});

it('lets the app schedule db-hound:flush itself', function () {
    config()->set('db-hound.flush.schedule', false);

    expect(scheduledFlushes())->toBeEmpty();
});

it('keeps running the scheduled flush for the file Store after capture is turned off', function () {
    // What the file Store buffered, Samples included, must still leave the disk.
    $flush = scheduledFlushes()[0];
    config()->set('db-hound.enabled', false);

    config()->set('db-hound.store', 'file');
    expect($flush->filtersPass(app()))->toBeTrue();

    config()->set('db-hound.store', 'redis');
    expect($flush->filtersPass(app()))->toBeFalse();
});
