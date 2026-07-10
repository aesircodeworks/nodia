<?php

use App\Identity\Capability;
use App\Identity\Exceptions\MissingCapabilityException;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Http\Middleware\RequireCapability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 7: the tenancy.platform group's
 * capability check, wired as RequireCapability::class.':tenants.manage' in
 * TenancyServiceProvider so tenant and domain CRUD require tenants.manage.
 * Drives the middleware directly the same way
 * tests/Unit/Identity/CapabilityGateTest.php drives CapabilityGate,
 * setting the staff guard's user and the platform tenant transaction the
 * real request pipeline (auth:staff, PlatformRequestTransaction) already
 * establishes ahead of it in the tenancy.platform group.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
    });

    User::query()->delete();
    Auth::forgetGuards();
});

function requireCapabilityMember(Capability $capability): User
{
    $user = User::factory()->create();
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

    return $user;
}

it('calls the next handler when the acting membership holds the named capability', function () {
    $user = requireCapabilityMember(Capability::TenantsManage);

    $response = app(TenantTransaction::class)->asPlatform(function () use ($user) {
        Auth::guard('staff')->setUser($user);

        return app(RequireCapability::class)->handle(
            Request::create('/v1/tenants'),
            fn () => response()->noContent(),
            Capability::TenantsManage->value,
        );
    });

    expect($response->getStatusCode())->toBe(204);
});

it('throws MissingCapabilityException when the acting membership lacks the named capability', function () {
    $user = requireCapabilityMember(Capability::EventsView);

    expect(fn () => app(TenantTransaction::class)->asPlatform(function () use ($user) {
        Auth::guard('staff')->setUser($user);

        return app(RequireCapability::class)->handle(
            Request::create('/v1/tenants'),
            fn () => response()->noContent(),
            Capability::TenantsManage->value,
        );
    }))->toThrow(MissingCapabilityException::class);
});
