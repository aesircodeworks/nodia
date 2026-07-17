<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Identity\Capability;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;

/**
 * Issues a staff bearer for a user holding a platform-scope membership
 * (stage-03 plan Data model: "Platform-scope memberships use the sentinel
 * platform tenant") whose role carries the given capability, for feature
 * tests exercising the tenancy.platform route group now that it requires
 * Passport bearer authentication plus a capability (task breakdown item
 * 7). No seeded template role carries tenants.manage (task-04 journal:
 * "Owner gets every capability except tenants.manage, a platform-only
 * concern"), so a dedicated role scoped to the sentinel platform tenant is
 * created per call rather than reusing a template.
 *
 * Platform-scope memberships are unconditionally MFA-enforcing (stage-03
 * plan, MFA enforcement paragraph, task breakdown item 11): every caller
 * this helper mints has MFA confirmed already, so App\Http\Middleware\
 * EnforceMfaCompliance never blocks the tenancy.platform requests every
 * other platform-scope feature test in this suite predates task breakdown
 * item 11 by relying on. The token is issued first, while mfa_enabled is
 * still false (a real enrolled user's login would supply mfa_code; this
 * setup shortcut instead flips the columns after the password-only
 * exchange, which is equivalent from the enforcement middleware's
 * perspective since it only ever reads current column state, never how
 * the presented access token was originally obtained). A test that
 * specifically exercises an unconfirmed platform-scope caller builds its
 * own user rather than using this helper.
 */
final class PlatformStaff
{
    public static function token(Capability $capability = Capability::TenantsManage): string
    {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

        $sentinel = config()->string('tenancy.platform_tenant_id');

        app(TenantTransaction::class)->asPlatform(function () use ($user, $capability, $sentinel): void {
            Membership::factory()->platform()->create([
                'user_id' => $user->id,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $sentinel,
                    'capabilities' => [$capability->value],
                ])->id,
            ]);
        });

        return $token;
    }
}
