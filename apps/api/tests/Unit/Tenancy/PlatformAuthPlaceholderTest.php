<?php

use App\Tenancy\Http\Middleware\PlatformAuthPlaceholder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

it('passes the request through in the testing and local environments', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    $response = app(PlatformAuthPlaceholder::class)->handle(
        Request::create('/v1/tenants'),
        fn () => response()->noContent(),
    );

    expect($response->getStatusCode())->toBe(204);
})->with(['testing', 'local']);

it('denies the request in every other environment', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    expect(fn () => app(PlatformAuthPlaceholder::class)->handle(
        Request::create('/v1/tenants'),
        fn () => response()->noContent(),
    ))->toThrow(AuthenticationException::class);
})->with(['production', 'staging']);
