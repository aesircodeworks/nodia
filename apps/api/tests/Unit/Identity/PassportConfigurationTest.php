<?php

use App\Identity\IdentityServiceProvider;
use App\Identity\Models\Customer;
use App\Models\User;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;

it('sets the access token lifetime from config, not Passport defaults', function (): void {
    expect(config()->integer('identity.access_token_ttl_minutes'))->toBe(15);

    (new IdentityServiceProvider(app()))->boot();

    expect(Passport::tokensExpireIn())->toEqual(new DateInterval('PT15M'))
        ->and(Passport::tokensExpireIn())->not->toEqual(new DateInterval('P1Y'));
});

it('sets the refresh token lifetime from config, not Passport defaults', function (): void {
    $minutes = config()->integer('identity.refresh_token_ttl_minutes');

    (new IdentityServiceProvider(app()))->boot();

    expect(Passport::refreshTokensExpireIn())->toEqual(new DateInterval("PT{$minutes}M"))
        ->and(Passport::refreshTokensExpireIn())->not->toEqual(new DateInterval('P1Y'));
});

it('reacts to a changed config value rather than caching Passport defaults', function (): void {
    config()->set('identity.access_token_ttl_minutes', 42);

    (new IdentityServiceProvider(app()))->boot();

    expect(Passport::tokensExpireIn())->toEqual(new DateInterval('PT42M'));
});

it('enables the password grant', function (): void {
    (new IdentityServiceProvider(app()))->boot();

    expect(Passport::$passwordGrantEnabled)->toBeTrue();
});

it('disables Passports own HTML routes in favor of the /v1/auth controllers', function (): void {
    (new IdentityServiceProvider(app()))->boot();

    expect(Passport::$registersRoutes)->toBeFalse();
});

it('wires the staff guard to the passport driver and the users provider', function (): void {
    expect(config('auth.guards.staff'))->toBe([
        'driver' => 'passport',
        'provider' => 'users',
    ]);
});

it('wires the customer guard to the passport driver and the customers provider', function (): void {
    expect(config('auth.guards.customer'))->toBe([
        'driver' => 'passport',
        'provider' => 'customers',
    ]);
});

it('wires the customers provider to the Customer model', function (): void {
    expect(config('auth.providers.customers'))->toBe([
        'driver' => 'eloquent',
        'model' => Customer::class,
    ]);
});

it('makes both identity models OAuthenticatable so Passport can act on them', function (): void {
    expect(new User)->toBeInstanceOf(OAuthenticatable::class)
        ->and(new Customer)->toBeInstanceOf(OAuthenticatable::class);
});
