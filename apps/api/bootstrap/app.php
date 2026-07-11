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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*'),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ProblemRenderer::class)->render($e, $request),
        );
    })->create();
