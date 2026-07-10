<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

it('ships a published Horizon configuration file', function (): void {
    expect(File::exists(config_path('horizon.php')))->toBeTrue()
        ->and(config('horizon.path'))->toBe('horizon')
        ->and(config('horizon.use'))->toBe('default')
        ->and(config('horizon.defaults'))->toHaveKey('supervisor-1')
        ->and(config('horizon.defaults.supervisor-1.connection'))->toBe('redis');
});

it('configures redis supervisors and database-uuids failed job storage', function (): void {
    // phpunit.xml forces QUEUE_CONNECTION=sync so the suite never opens a
    // Redis worker; the published production default is still redis.
    expect(File::get(config_path('queue.php')))
        ->toMatch("/env\\('QUEUE_CONNECTION',\\s*'redis'\\)/")
        ->and(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.table'))->toBe('failed_jobs')
        ->and(config('queue.batching.table'))->toBe('job_batches')
        ->and(config('horizon.defaults.supervisor-1.connection'))->toBe('redis');
});

it('registers the viewHorizon gate as deny-by-default', function (): void {
    expect(Gate::has('viewHorizon'))->toBeTrue()
        ->and(Gate::check('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->check('viewHorizon'))->toBeFalse();
});

it('denies Horizon dashboard access outside the local environment', function (): void {
    $previous = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(app()->environment('local'))->toBeFalse()
            ->and(Horizon::check(Request::create('/horizon', 'GET')))->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

it('allows Horizon dashboard access in the local environment only via the environment check', function (): void {
    $previous = app()->environment();
    app()->detectEnvironment(fn () => 'local');

    try {
        expect(Gate::check('viewHorizon'))->toBeFalse()
            ->and(Horizon::check(Request::create('/horizon', 'GET')))->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

it('does not mount Horizon under the /v1 API prefix', function (): void {
    $paths = collect(app('router')->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'horizon'));

    expect($paths)->not->toBeEmpty()
        ->and($paths->every(fn (string $uri) => ! str_starts_with($uri, 'v1/')))->toBeTrue();
});
