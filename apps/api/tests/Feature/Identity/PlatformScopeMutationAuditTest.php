<?php

use App\Identity\Capability;
use App\Models\User;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 7 (task breakdown item 15): "platform-role
 * cross-tenant access writes an entry flagged as platform-scope use,"
 * the audit note task-05's own journal deferred to this task ("activity-
 * log recording of this platform-role use is explicitly deferred to
 * task-15 per the task description, not implemented here"). A
 * platform-scope membership holding roles.manage reaches a tenant it has
 * no direct tenant-scope membership in through ResolveTenantAccess's
 * platform-scope fallback (TenantTransaction::elevateToPlatformRole);
 * RecordActivityAudit (attached to the roles.php mutating group) tags
 * the resulting entry platform_scope via the ambient TenantContext, with
 * no special-casing of its own.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    foreach ([$this->tenantId, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

it('records the mutation under the real target tenant, flagged platform-scope, for an elevated platform-scope caller', function () {
    $token = PlatformStaff::token(Capability::RolesManage);

    $this->postJson('/v1/roles', [
        'name' => 'Elevated Role',
        'capabilities' => ['events.view'],
    ], [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $this->tenantId,
    ])->assertCreated();

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'mutation')
            ->where('tenant_id', $this->tenantId)
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->tenant_id)->toBe($this->tenantId)
        ->and($entry->tenant_id)->not->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->properties->get('platform_scope'))->toBeTrue();
});
