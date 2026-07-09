<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture's two tenants with the memberships and roles
 * rows the Identity isolation suite runs against: one custom role per
 * tenant, the platform-seeded "Owner" template, and a multi-tenant staff
 * member (USER_A) alongside a single-tenant one (USER_B), so the
 * memberships_self_read probes can prove a caller's own rows are visible
 * across tenants while another user's rows never are (stage-03 plan,
 * Slice 3 risk: "the memberships_self_read policy returns exactly the
 * caller's own rows across tenants and no one else's").
 */
final class IdentityFixture
{
    public const USER_A = '019797f0-0000-7000-8000-0000000000a1';

    public const USER_B = '019797f0-0000-7000-8000-0000000000b1';

    public const ROLE_A = '019797f0-0000-7000-8000-0000000000a2';

    public const ROLE_B = '019797f0-0000-7000-8000-0000000000b2';

    public const MEMBERSHIP_A_IN_TENANT_A = '019797f0-0000-7000-8000-0000000000a3';

    public const MEMBERSHIP_A_IN_TENANT_B = '019797f0-0000-7000-8000-0000000000a4';

    public const MEMBERSHIP_B_IN_TENANT_B = '019797f0-0000-7000-8000-0000000000b3';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            User::factory()->create(['id' => self::USER_A, 'email' => 'user-a@identity-fixture.example']);
            User::factory()->create(['id' => self::USER_B, 'email' => 'user-b@identity-fixture.example']);

            // Written under nodia_platform (using(true)/with check(true) via
            // roles_platform_write), the only write path a per-tenant custom
            // role's own tenant_isolation policy would otherwise also allow
            // from nodia_app; platform keeps fixture setup uniform across
            // both tenants in one block, mirroring TenantFixture::seed().
            Role::factory()->create(['id' => self::ROLE_A, 'tenant_id' => TenantFixture::TENANT_A, 'name' => 'Custom A']);
            Role::factory()->create(['id' => self::ROLE_B, 'tenant_id' => TenantFixture::TENANT_B, 'name' => 'Custom B']);
        });

        // Memberships carry no platform write policy (stage-03 plan: "standard
        // single-table policy"), so each row is written under nodia_app with
        // app.tenant_id set to the membership's own tenant, the way the
        // future InviteUser/AssignRole Actions will run.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Membership::factory()->create([
                'id' => self::MEMBERSHIP_A_IN_TENANT_A,
                'user_id' => self::USER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'role_id' => self::ROLE_A,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Membership::factory()->create([
                'id' => self::MEMBERSHIP_A_IN_TENANT_B,
                'user_id' => self::USER_A,
                'tenant_id' => TenantFixture::TENANT_B,
                'role_id' => self::ROLE_B,
                'scope' => MembershipScope::Tenant,
            ]);

            Membership::factory()->create([
                'id' => self::MEMBERSHIP_B_IN_TENANT_B,
                'user_id' => self::USER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'role_id' => self::ROLE_B,
                'scope' => MembershipScope::Tenant,
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under the same posture each row was written under:
        // memberships hold no platform write policy, so nodia_platform
        // alone could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('memberships')->where('id', self::MEMBERSHIP_A_IN_TENANT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('memberships')->whereIn('id', [
                self::MEMBERSHIP_A_IN_TENANT_B,
                self::MEMBERSHIP_B_IN_TENANT_B,
            ])->delete();
        });

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('roles')->whereIn('id', [self::ROLE_A, self::ROLE_B])->delete();

            DB::table('users')->whereIn('id', [self::USER_A, self::USER_B])->delete();
        });

        TenantFixture::clean();
    }
}
