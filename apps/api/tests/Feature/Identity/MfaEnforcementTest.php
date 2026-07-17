<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, MFA enforcement paragraph, task breakdown item 11: when
 * the acting membership is platform-scope, or its role's capability set
 * intersects the financially privileged set, every request outside the
 * auth and MFA-enrollment endpoints is denied with mfa_enforcement_required
 * until MFA is confirmed. Proven here against GET /v1/roles (a
 * capability-free read, so the denial cannot be mistaken for
 * missing_capability) and the tenancy.platform group task-07 left
 * unenforced until this task.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    if (isset($this->tenantId)) {
        app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
            DB::table('memberships')->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('memberships')->where('tenant_id', config()->string('tenancy.platform_tenant_id'))->delete();
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('denies an unconfirmed platform-scope caller mfa_enforcement_required on GET /v1/roles but still reaches MFA enrollment', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $sentinel = config()->string('tenancy.platform_tenant_id');
    app(TenantTransaction::class)->asPlatform(function () use ($user, $sentinel): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['tenant_id' => $sentinel])->id,
        ]);
    });

    $this->getJson('/v1/roles', ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $sentinel])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'mfa_enforcement_required');

    $this->postJson('/v1/auth/mfa/enrollment', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();
});

it('denies an unconfirmed platform-scope caller mfa_enforcement_required on the tenancy.platform group (GET /v1/tenants)', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $sentinel = config()->string('tenancy.platform_tenant_id');
    app(TenantTransaction::class)->asPlatform(function () use ($user, $sentinel): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::factory()->create(['tenant_id' => $sentinel, 'capabilities' => ['tenants.manage']])->id,
        ]);
    });

    $this->getJson('/v1/tenants', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'mfa_enforcement_required');
});

it('denies an unconfirmed tenant-scope caller whose role holds orders.refund mfa_enforcement_required on GET /v1/roles but still reaches MFA enrollment', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $this->tenantId, 'capabilities' => ['orders.refund']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->getJson('/v1/roles', ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $this->tenantId])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'mfa_enforcement_required');

    $this->postJson('/v1/auth/mfa/enrollment', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();
});

it('lets a non-privileged tenant-scope caller through without confirmed MFA', function (): void {
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $this->tenantId, 'capabilities' => ['events.view']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->getJson('/v1/roles', ['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $this->tenantId])
        ->assertOk();
});
