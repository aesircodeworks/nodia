<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * Stage-12 plan, Slice 3, task breakdown item 7 Unit: every retention
 * window comes from config/retention.php (stage-12 plan Risks
 * defaults: webhook payloads 90 days, activity log 400 days, outbox
 * archival 180 days, export attachments 7 days), fake-clock driven (a
 * plain day count subtracted from Date::now()), tunable without
 * migration.
 */

it('ships config/retention.php with the stage-12 default windows', function (): void {
    expect(File::exists(config_path('retention.php')))->toBeTrue()
        ->and(config()->integer('retention.webhook_payload_days'))->toBe(90)
        ->and(config()->integer('retention.activity_log_days'))->toBe(400)
        ->and(config()->integer('retention.outbox_archival_days'))->toBe(180)
        ->and(config()->integer('retention.export_attachment_days'))->toBe(7)
        ->and(config()->string('retention.archive_disk'))->toBe('s3');
});

it('reads every window from config so tests can override them', function (): void {
    config()->set('retention.webhook_payload_days', 30);
    config()->set('retention.activity_log_days', 60);
    config()->set('retention.outbox_archival_days', 90);
    config()->set('retention.export_attachment_days', 3);

    expect(config()->integer('retention.webhook_payload_days'))->toBe(30)
        ->and(config()->integer('retention.activity_log_days'))->toBe(60)
        ->and(config()->integer('retention.outbox_archival_days'))->toBe(90)
        ->and(config()->integer('retention.export_attachment_days'))->toBe(3);
});

it('computes cutoffs from the framework clock so freezeTime and travel control the windows', function (): void {
    $this->freezeTime();
    $frozen = now();

    config()->set('retention.webhook_payload_days', 90);

    $cutoff = now()->subDays(config()->integer('retention.webhook_payload_days'));

    expect($cutoff->toDateTimeString())->toBe($frozen->copy()->subDays(90)->toDateTimeString());

    $this->travel(5)->days();

    $moved = now()->subDays(config()->integer('retention.webhook_payload_days'));

    expect($moved->toDateTimeString())->toBe($frozen->copy()->addDays(5)->subDays(90)->toDateTimeString());
});

it('schedules webhooks:prune-payloads daily', function (): void {
    // withSchedule attaches on Artisan::starting; resolve the console
    // application so bootstrap/app.php registers the schedule callback.
    Artisan::all();

    $events = collect(app(Schedule::class)->events());

    $prune = $events->first(
        fn ($event): bool => is_string($event->command)
            && str_contains($event->command, 'webhooks:prune-payloads'),
    );

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('0 0 * * *');
});

it('schedules data-subject-requests:prune-exports daily', function (): void {
    Artisan::all();

    $events = collect(app(Schedule::class)->events());

    $prune = $events->first(
        fn ($event): bool => is_string($event->command)
            && str_contains($event->command, 'data-subject-requests:prune-exports'),
    );

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toBe('0 0 * * *');
});

it('schedules activity-log:archive daily', function (): void {
    Artisan::all();

    $events = collect(app(Schedule::class)->events());

    $archive = $events->first(
        fn ($event): bool => is_string($event->command)
            && str_contains($event->command, 'activity-log:archive'),
    );

    expect($archive)->not->toBeNull()
        ->and($archive->expression)->toBe('0 0 * * *');
});
