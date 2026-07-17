<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\EventSigningKeyFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * event_signing_keys is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-09 plan, Data model
 * "event_signing_keys"), mirroring check_ins. Written first per the
 * master plan's TDD sequencing (stage-09 plan, Slice 1).
 */

/**
 * @return array<string, mixed>
 */
function validEventSigningKeyRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'key_version' => 2,
        'secret' => 'plain-secret-value',
        'status' => 'active',
        'activated_at' => now(),
        'retired_at' => null,
        'revoked_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => EventSigningKeyFixture::seed());

afterEach(fn () => EventSigningKeyFixture::clean());

it('shows a tenant only its own signing keys', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_signing_keys')->pluck('id'),
    );

    expect($ids->all())->toBe([EventSigningKeyFixture::KEY_A]);
});

it('finds nothing across tenants with a cross-tenant select', function () {
    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_B)->exists(),
    );

    expect($exists)->toBeFalse();
});

it('rejects a cross-tenant insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_signing_keys')->insert(validEventSigningKeyRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_B)->update(['status' => 'revoked']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_B)->value('status'),
    );

    expect($status)->toBe('active');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from event_signing_keys'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(EventSigningKeyFixture::KEY_A);
});

it('lets nodia_platform read every tenant\'s signing keys without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('event_signing_keys')->pluck('id'));

    expect($ids->all())->toContain(EventSigningKeyFixture::KEY_A, EventSigningKeyFixture::KEY_B);
});

it('rejects a nodia_platform write with no tenant asserted, event_signing_keys has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('event_signing_keys')->where('id', EventSigningKeyFixture::KEY_A)->update(['status' => 'revoked']),
    );

    expect($affected)->toBe(0);
});
