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
 * tenant, whose role carries the given capability or capabilities,
 * mirroring Tests\Support\PlatformStaff's own precedent for the
 * tenancy.platform group but for the tenancy.admin group (X-Tenant-Id
 * resolution against memberships, stage-03 plan). MFA is confirmed
 * unconditionally, the same way PlatformStaff does, so this helper stays
 * usable once a later stage's financially privileged capability is
 * passed in without every call site having to know about
 * MfaEnforcementPolicy. Accepts either a single Capability (task-01's own
 * usage) or a list (stage-05a task-02 onward, where an endpoint's own
 * feature test wants one bearer holding both the read and the write
 * capability, e.g. events.view and events.manage together).
 */
final class TenantStaff
{
    /**
     * @param  Capability|list<Capability>  $capabilities
     */
    public static function token(string $tenantId, Capability|array $capabilities): string
    {
        $capabilities = is_array($capabilities) ? $capabilities : [$capabilities];

        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

        app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $capabilities): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $tenantId,
                    'capabilities' => array_map(fn (Capability $c): string => $c->value, $capabilities),
                ])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        return $token;
    }
}
