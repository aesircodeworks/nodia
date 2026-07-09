<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

/**
 * Issues a real staff access token through the actual HTTP token endpoint,
 * mirroring how a genuine client obtains one, for feature tests that need
 * a bearer credential to reach the tenancy.admin group now that
 * ResolveTenantFromHeader requires staff authentication (stage-03
 * task-05).
 */
final class StaffTokens
{
    public static function issue(User $user, string $password = 'password'): string
    {
        $token = test()->postJson('/v1/auth/staff/token', [
            'email' => $user->email,
            'password' => $password,
        ])->json('access_token');

        assert(is_string($token));

        return $token;
    }
}
