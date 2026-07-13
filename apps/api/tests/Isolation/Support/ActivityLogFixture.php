<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds on TenantFixture's two tenants with one activity_log row per
 * tenant, the two-tenant fixture stage-03 plan task breakdown item 14
 * names. No Eloquent model exists for this table yet (task-14 is a pure
 * migration task per the plan; the Slice 7 logger wrapper that actually
 * records entries is a later task), so rows are seeded and probed with
 * raw DB::table() calls, the same way the roles isolation suite's
 * forged-insert and raw-sql-bypass probes already do.
 *
 * seed() generates fresh row ids on every call rather than reusing fixed
 * constants (CustomerFixture's own pattern): activity_log grants no
 * UPDATE privilege to any role, and DELETE is granted to nodia_platform
 * only, restricted by a policy to rows past a cutoff the retention
 * pruning command supplies explicitly (stage-12 plan task breakdown
 * item 8, 2026_07_13_000062_add_scoped_delete_policy_to_activity_log_
 * table.php) — rows seeded here at "now" are never past a realistic
 * cutoff, so clean() still cannot (and need not) remove the rows a
 * previous test's seed() call inserted, the way every other fixture's
 * clean() does. Reusing a fixed id would collide on the
 * primary key the second time a test in the same file runs. The returned
 * ids are scoped to the still-shared TenantFixture::TENANT_A/TENANT_B
 * (safe to keep reusing those: this table has no foreign key to tenants,
 * precisely so TenantFixture's own per-test recreate-and-delete cycle
 * keeps working). Tests assert against the specific ids seed() returns,
 * never a total row count, since earlier tests in the same file leave
 * their own rows behind permanently for the rest of the process.
 */
final class ActivityLogFixture
{
    /**
     * @return array{tenantA: string, tenantB: string}
     */
    public static function seed(): array
    {
        TenantFixture::seed();

        $entryA = Str::uuid7()->toString();
        $entryB = Str::uuid7()->toString();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($entryA): void {
            DB::table('activity_log')->insert([
                'id' => $entryA,
                'tenant_id' => TenantFixture::TENANT_A,
                'log_name' => 'default',
                'description' => 'tenant a entry',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($entryB): void {
            DB::table('activity_log')->insert([
                'id' => $entryB,
                'tenant_id' => TenantFixture::TENANT_B,
                'log_name' => 'default',
                'description' => 'tenant b entry',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return ['tenantA' => $entryA, 'tenantB' => $entryB];
    }

    public static function clean(): void
    {
        // No delete: fixture rows are seeded at "now", never past the
        // pruning command's cutoff, so the narrow platform-role delete
        // path this table now carries (stage-12 task breakdown item 8)
        // still cannot touch them; this table also carries no foreign
        // key to tenants (see the migration's docblock), so leaving rows
        // behind does not block TenantFixture::clean()'s tenant teardown
        // the way it would for every other tenant-scoped table.
        TenantFixture::clean();
    }
}
