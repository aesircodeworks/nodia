<?php

namespace App\Identity;

use App\Identity\Authorization\CapabilityGate;
use App\Identity\OAuth\IdentityAccessToken;
use App\Identity\OAuth\IdentityRefreshTokenRepository;
use App\Identity\OAuth\RefreshTokenRotationContext;
use App\Support\Outbox\EventTypeRegistry;
use DateInterval;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

/**
 * Wires Passport for both identity populations (system-design 5.4).
 * Verified against current Passport documentation before this was
 * written (stage-03 plan, Risks: "Passport version behavior"): the
 * password grant is disabled by default since Passport 12.0 and must be
 * enabled explicitly; Passport's own /oauth/* HTML routes are switched
 * off because the /v1/auth/* controllers a later Stage 3 slice adds
 * drive the token machinery directly instead of Passport's controllers.
 * Token lifetimes are always set explicitly from config, never left on
 * Passport's one-year defaults (api-conventions).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PassportServiceProvider resolves Bridge\RefreshTokenRepository
        // (the concrete class, not an interface) through the container for
        // both the password grant and the refresh grant, so binding it
        // here reaches every code path that creates or rotates a refresh
        // token (stage-03 plan, Slice 2: reuse detection and family
        // revocation, App\Identity\OAuth\IdentityRefreshTokenRepository).
        $this->app->bind(RefreshTokenRepository::class, IdentityRefreshTokenRepository::class);

        // The repository captured by Passport's singleton resolves this
        // holder inside each repository operation. Octane therefore swaps
        // the scoped instance at the request boundary even though the
        // repository itself remains captured by AuthorizationServer.
        $this->app->scoped(RefreshTokenRotationContext::class);
    }

    public function boot(): void
    {
        // Type-name strings only: Support\Outbox never imports these classes
        // (stage-04 recording API registry; Identity producers in Slice 6).
        // Resolved from the container rather than method-injected so unit
        // tests that construct this provider and call boot() with no args
        // (PassportConfigurationTest) keep working.
        $this->app->make(EventTypeRegistry::class)->register('UserInvited');
        $this->app->make(EventTypeRegistry::class)->register('UserRoleChanged');
        $this->app->make(EventTypeRegistry::class)->register('CustomerRegistered');
        $this->app->make(EventTypeRegistry::class)->register('CustomerAnonymized');

        CapabilityGate::register();

        Passport::ignoreRoutes();
        Passport::enablePasswordGrant();
        Passport::useAccessTokenEntity(IdentityAccessToken::class);

        // A DateInterval, not a target DateTime, is passed deliberately:
        // Passport stores a DateTimeInterface argument as
        // Date::now()->diff($date), which would race the interval's own
        // now() call by a few microseconds and make the configured
        // lifetime unverifiable exactly in tests.
        Passport::tokensExpireIn(
            new DateInterval('PT'.config()->integer('identity.access_token_ttl_minutes').'M'),
        );

        Passport::refreshTokensExpireIn(
            new DateInterval('PT'.config()->integer('identity.refresh_token_ttl_minutes').'M'),
        );

        Route::prefix('v1')->group(__DIR__.'/Http/routes/auth.php');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/customer-auth.php');

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/roles.php');

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/memberships.php');

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/customers.php');
    }
}
