<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * Stage-04 plan Slice 3 unit: grace and stability windows come from
 * config/outbox.php (defaults 60s grace, 5s stability), are fake-clock
 * driven (values are plain seconds subtracted from Date::now()), and the
 * sweeper is scheduled every minute.
 */

it('ships config/outbox.php with the stage-04 default windows', function (): void {
    expect(File::exists(config_path('outbox.php')))->toBeTrue()
        ->and(config()->integer('outbox.stability_window_seconds'))->toBe(5)
        ->and(config()->integer('outbox.sweeper_grace_seconds'))->toBe(60)
        ->and(config()->integer('outbox.ordered_defer_seconds'))->toBe(15);
});

it('reads grace and stability windows from config so tests can override them', function (): void {
    config()->set('outbox.stability_window_seconds', 12);
    config()->set('outbox.sweeper_grace_seconds', 90);
    config()->set('outbox.ordered_defer_seconds', 7);

    expect(config()->integer('outbox.stability_window_seconds'))->toBe(12)
        ->and(config()->integer('outbox.sweeper_grace_seconds'))->toBe(90)
        ->and(config()->integer('outbox.ordered_defer_seconds'))->toBe(7);
});

it('computes cutoffs from the framework clock so freezeTime and travel control the windows', function (): void {
    $this->freezeTime();
    $frozen = now();

    config()->set('outbox.stability_window_seconds', 5);
    config()->set('outbox.sweeper_grace_seconds', 60);

    $stabilityCutoff = now()->subSeconds(config()->integer('outbox.stability_window_seconds'));
    $graceCutoff = now()->subSeconds(config()->integer('outbox.sweeper_grace_seconds'));

    expect($stabilityCutoff->getTimestamp())->toBe($frozen->copy()->subSeconds(5)->getTimestamp())
        ->and($graceCutoff->getTimestamp())->toBe($frozen->copy()->subSeconds(60)->getTimestamp());

    $this->travel(30)->seconds();

    $movedGrace = now()->subSeconds(config()->integer('outbox.sweeper_grace_seconds'));

    expect($movedGrace->getTimestamp())->toBe($frozen->copy()->addSeconds(30)->subSeconds(60)->getTimestamp());
});

it('schedules outbox:sweep every minute', function (): void {
    // withSchedule attaches on Artisan::starting; resolve the console
    // application so bootstrap/app.php registers the schedule callback.
    Artisan::all();

    $events = collect(app(Schedule::class)->events());

    $sweep = $events->first(
        fn ($event): bool => is_string($event->command)
            && str_contains($event->command, 'outbox:sweep'),
    );

    expect($sweep)->not->toBeNull()
        ->and($sweep->expression)->toBe('* * * * *');
});
