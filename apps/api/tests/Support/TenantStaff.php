<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;

/**
 * Issues a staff bearer holding a tenant-scope membership in the given
 * tenant, whose role carries the given capability, mirroring
 * Tests\Support\PlatformStaff's own precedent for the tenancy.platform
 * group but for the tenancy.admin group (X-Tenant-Id resolution against
 * memberships, stage-03 plan). MFA is confirmed unconditionally, the same
 * way PlatformStaff does, so this helper stays usable once a later
 * stage's financially privileged capability is passed in without every
 * call site having to know about MfaEnforcementPolicy.
 */
final class TenantStaff
{
    public static function token(string $tenantId, Capability $capability): string
    {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

        app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $capability): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $tenantId,
                    'capabilities' => [$capability->value],
                ])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        return $token;
    }
}
