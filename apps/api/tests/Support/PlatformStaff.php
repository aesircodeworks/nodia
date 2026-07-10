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
 */
final class PlatformStaff
{
    public static function token(Capability $capability = Capability::TenantsManage): string
    {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

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
