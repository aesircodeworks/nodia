<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\LedgerEntryFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * ledger_entries is the standard Rls::applyTenantPolicies posture
 * (stage-08b plan, Data model "ledger_entries"). Written first per the
 * master plan's non-negotiable rule for new tenant-scoped tables. Rows
 * accumulate for the process (the append-only trigger blocks DELETE and
 * the honest connection has no TRUNCATE), so every assertion scopes by
 * the ids seeded for the current test rather than counting globally.
 */

beforeEach(fn () => LedgerEntryFixture::seed());

it('shows a tenant only its own ledger entries', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        LedgerEntryFixture::TENANT_A,
        fn () => DB::table('ledger_entries')
            ->whereIn('id', [LedgerEntryFixture::$entryA, LedgerEntryFixture::$entryB])
            ->pluck('id'),
    );

    expect($ids->all())->toBe([LedgerEntryFixture::$entryA]);
});

it('shows a tenant no foreign rows at all', function () {
    $foreign = actingAsRole(
        Rls::APP_ROLE,
        LedgerEntryFixture::TENANT_A,
        fn () => DB::table('ledger_entries')->where('tenant_id', '!=', LedgerEntryFixture::TENANT_A)->count(),
    );

    expect($foreign)->toBe(0);
});

it('rejects an insert claiming another tenant id', function () {
    actingAsRole(Rls::APP_ROLE, LedgerEntryFixture::TENANT_A, function (): void {
        DB::table('ledger_entries')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => LedgerEntryFixture::TENANT_B,
            'account' => 'tenant_net',
            'direction' => 'credit',
            'amount' => 500,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => Str::uuid7()->toString(),
            'source_event_id' => Str::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
})->throws(QueryException::class, 'row-level security');

it('makes a cross-tenant update affect zero rows before the append-only trigger can even fire', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        LedgerEntryFixture::TENANT_A,
        fn () => DB::table('ledger_entries')->where('id', LedgerEntryFixture::$entryB)->update(['amount' => 1]),
    );

    expect($affected)->toBe(0);
});

it('raises the append-only trigger on a same-tenant delete', function () {
    actingAsRole(Rls::APP_ROLE, LedgerEntryFixture::TENANT_A, function (): void {
        DB::table('ledger_entries')->where('id', LedgerEntryFixture::$entryA)->delete();
    });
})->throws(QueryException::class, 'append-only');

it('grants the platform role read across tenants', function () {
    $count = actingAsRole(
        Rls::PLATFORM_ROLE,
        LedgerEntryFixture::TENANT_A,
        fn () => DB::table('ledger_entries')
            ->whereIn('id', [LedgerEntryFixture::$entryA, LedgerEntryFixture::$entryB])
            ->count(),
    );

    expect($count)->toBe(2);
});
