<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\LedgerEntryFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-08b plan, Slice 8 mandates endpoint-level isolation for the new
 * ledger read routes: exercises the real GET /v1/ledger-entries and
 * GET /v1/ledger-balances handlers through the tenancy.admin group,
 * proving the ledger_entries table's tenant policy keeps tenant A's rows
 * genuinely invisible to a tenant B caller who holds ledger.view, not
 * merely denied by a capability check. Runs under the downgraded
 * nodia_isolation connection, so a RLS regression fails loudly rather
 * than leaking through a BYPASSRLS default. ledger.view is financially
 * privileged (Capability::isFinanciallyPrivileged), so the bearer's user
 * carries a confirmed MFA session to clear EnforceMfaCompliance.
 *
 * One issueLedgerBToken() call per test: Illuminate\Auth\RequestGuard
 * caches the resolved user on the guard instance once resolved, the same
 * hazard EventMediaEndpointsIsolationTest records.
 */

beforeEach(function (): void {
    LedgerEntryFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, LedgerEntryFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', LedgerEntryFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });
});

function issueLedgerBToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => LedgerEntryFixture::TENANT_B,
            'name' => 'Ledger Tenant B Reader',
            'capabilities' => ['ledger.view'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    // Issue before confirming MFA: the staff token endpoint rejects a
    // credential login once the account has MFA enabled. ledger.view is
    // financially privileged, so the resolved session still needs a
    // confirmed MFA session to clear EnforceMfaCompliance.
    $token = StaffTokens::issue($user);
    actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save(),
    );

    actingAsRole(Rls::APP_ROLE, LedgerEntryFixture::TENANT_B, function () use ($user, $roleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => LedgerEntryFixture::TENANT_B,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('never lists a foreign tenant entry on ledger-entries', function () {
    $token = issueLedgerBToken();

    // Ledger rows accumulate for the process (append-only, never cleaned),
    // so page generously to keep tenant B's own newest entry on the page.
    $response = test()->getJson('/v1/ledger-entries?per_page=100', [
        'X-Tenant-Id' => LedgerEntryFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toContain(LedgerEntryFixture::$entryB)
        ->and($ids)->not->toContain(LedgerEntryFixture::$entryA);
});

it('never sums a foreign tenant entry into ledger-balances', function () {
    // A distinctive tenant A entry: ledger rows accumulate for the process
    // (append-only, never cleaned), so an exact USD tenant_net total is not
    // stable across runs. A currency tenant B never uses proves the aggregate
    // is scoped: if tenant A's rows leaked, a JPY row would surface.
    $probeCurrency = 'JPY';
    actingAsRole(Rls::APP_ROLE, LedgerEntryFixture::TENANT_A, function () use ($probeCurrency): void {
        DB::table('ledger_entries')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => LedgerEntryFixture::TENANT_A,
            'account' => 'platform_commission',
            'direction' => 'credit',
            'amount' => 7777,
            'currency' => $probeCurrency,
            'reference_type' => 'payment',
            'reference_id' => Str::uuid7()->toString(),
            'source_event_id' => Str::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $token = issueLedgerBToken();

    $response = test()->getJson('/v1/ledger-balances', [
        'X-Tenant-Id' => LedgerEntryFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $currencies = array_column($response->json('data'), 'currency');

    expect($currencies)->not->toContain($probeCurrency);
});
