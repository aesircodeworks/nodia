<?php

namespace App\Identity;

use App\Identity\OAuth\IdentityAccessToken;
use DateInterval;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
    public function boot(): void
    {
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
    }
}
