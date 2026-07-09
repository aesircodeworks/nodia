<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

/**
 * Passport's AuthorizationServer and ResourceServer bindings are lazy
 * (never resolved during boot, stage-03 task-01 journal), so nothing
 * before this stage's tests needed real RSA keys on disk. Every test that
 * issues or validates a Passport JWT now does, and neither the app image
 * nor CI provisions them, so the suite provisions its own once per
 * process (storage/oauth-*.key is gitignored, matching production, where
 * keys are provisioned by deployment tooling, not committed).
 */
final class OAuthKeys
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $privateKey = Passport::keyPath('oauth-private.key');
        $publicKey = Passport::keyPath('oauth-public.key');

        if (! file_exists($privateKey) || ! file_exists($publicKey)) {
            Artisan::call('passport:keys', ['--force' => true]);
        }

        self::$ensured = true;
    }
}
