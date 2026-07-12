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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*'),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ProblemRenderer::class)->render($e, $request),
        );
    })->create();
