<?php

use App\Http\Middleware\CorrelationId;
use App\Support\Problems\ProblemRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationId::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Stage-04 plan task 8: reconciliation sweeper every minute
        // against config/outbox.php grace and stability windows.
        $schedule->command('outbox:sweep')->everyMinute();

        // Stage-06 plan, Slice 3, task breakdown item 6: expire active
        // holds past their expires_at every minute.
        $schedule->command('holds:release-expired')->everyMinute();

        // Stage-08a plan, Slice 7: expire initiated payments past their
        // confirmation window every minute; poll the gateway for missed
        // webhooks every 5 minutes (system-design 13).
        $schedule->command('payments:expire')->everyMinute();
        $schedule->command('payments:reconcile')->everyFiveMinutes();
        $schedule->command('payments:reconcile-refunds')->everyFiveMinutes();

        // Stage-08c plan, Slice 6: poll the gateway for payouts missed
        // by webhooks and reconcile mirrored payouts against it
        // (system-design 13).
        $schedule->command('payments:reconcile-payouts')->everyFiveMinutes();

        // Stage-10 plan, task breakdown item 8: admit waiting-room
        // entrants into checkout at each flagged event's own configured
        // per-minute rate. Sub-minute cadence so a per-minute admission
        // rate feels smooth (stage-10 plan Risks "Gatekeeper cadence").
        // Verified against the pinned Laravel version's own docs
        // (scheduling.md, "Sub-Minute Scheduled Tasks", Laravel 13.x):
        // once a sub-minute task is registered, schedule:run keeps
        // running through the remainder of the minute to invoke it, and
        // schedule:work (this app's own scheduler-container command,
        // system-design 16.3) already loops continuously and re-invokes
        // the scheduler every minute the same way -- no supervised
        // long-running command fallback is needed. Per-tick work stays
        // cheap (one Redis SMEMBERS, one batched cross-tenant Postgres
        // SELECT, one Lua eval per active event), so it runs directly
        // like every other scheduled command in this file rather than
        // dispatching a queued job.
        $schedule->command('onsale:gatekeeper')->everyTenSeconds();

        // Stage-12 plan, Slice 3, task breakdown item 7: retention
        // windows are day-scale (config/retention.php), so both
        // pruners run daily rather than at the minute cadence the
        // sweepers above use.
        $schedule->command('webhooks:prune-payloads')->daily();
        $schedule->command('data-subject-requests:prune-exports')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*'),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ProblemRenderer::class)->render($e, $request),
        );
    })->create();
