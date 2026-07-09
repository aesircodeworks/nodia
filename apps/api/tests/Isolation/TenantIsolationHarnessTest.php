<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\ProbeTable;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsTenant;

beforeEach(function (): void {
    ProbeTable::drop('harness_probes');
    ProbeTable::createWithPolicy('harness_probes');
});

afterEach(function (): void {
    ProbeTable::drop('harness_probes');
});

function insertProbeRow(string $tenantId, string $label = 'probe'): string
{
    $id = Str::uuid7()->toString();

    actingAsTenant($tenantId, fn () => DB::table('harness_probes')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'label' => $label,
    ]));

    return $id;
}

it('lets a tenant see its own rows', function () {
    $id = insertProbeRow(TenantFixture::TENANT_A);

    $rows = actingAsTenant(
        TenantFixture::TENANT_A,
        fn () => DB::table('harness_probes')->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($id)
        ->and($rows->first()->tenant_id)->toBe(TenantFixture::TENANT_A);
});

it('hides another tenant rows from select', function () {
    insertProbeRow(TenantFixture::TENANT_A);

    $rows = actingAsTenant(
        TenantFixture::TENANT_B,
        fn () => DB::table('harness_probes')->get(),
    );

    expect($rows)->toHaveCount(0);
});

it('makes another tenant update affect zero rows', function () {
    $id = insertProbeRow(TenantFixture::TENANT_A);

    $affected = actingAsTenant(
        TenantFixture::TENANT_B,
        fn () => DB::table('harness_probes')->where('id', $id)->update(['label' => 'tampered']),
    );

    expect($affected)->toBe(0);

    $label = actingAsTenant(
        TenantFixture::TENANT_A,
        fn () => DB::table('harness_probes')->where('id', $id)->value('label'),
    );

    expect($label)->toBe('probe');
});

it('makes another tenant delete affect zero rows', function () {
    $id = insertProbeRow(TenantFixture::TENANT_A);

    $affected = actingAsTenant(
        TenantFixture::TENANT_B,
        fn () => DB::table('harness_probes')->where('id', $id)->delete(),
    );

    expect($affected)->toBe(0);

    $count = actingAsTenant(
        TenantFixture::TENANT_A,
        fn () => DB::table('harness_probes')->count(),
    );

    expect($count)->toBe(1);
});

it('rejects inserting a row bearing another tenant id', function () {
    expect(fn () => actingAsTenant(TenantFixture::TENANT_B, fn () => DB::table('harness_probes')->insert([
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'label' => 'forged',
    ])))->toThrow(QueryException::class, 'row-level security');

    $count = actingAsTenant(
        TenantFixture::TENANT_A,
        fn () => DB::table('harness_probes')->count(),
    );

    expect($count)->toBe(0);
});

it('exposes nothing to a query outside any tenant transaction', function () {
    insertProbeRow(TenantFixture::TENANT_A);

    try {
        $rows = DB::select('select * from harness_probes');
    } catch (QueryException $exception) {
        // Unset parameter on a fresh session, or an empty-string uuid cast
        // when an earlier SET LOCAL already defined app.tenant_id for the
        // session: both mean the policy admits nothing by default.
        expect($exception->getMessage())->toMatch(
            '/unrecognized configuration parameter|invalid input syntax for type uuid/',
        );

        return;
    }

    expect($rows)->toBe([]);
});

describe('meta-probe: a table without a policy leaks', function () {
    beforeEach(function (): void {
        ProbeTable::drop('harness_probes_unguarded');
        ProbeTable::createWithoutPolicy('harness_probes_unguarded');
    });

    afterEach(function (): void {
        ProbeTable::drop('harness_probes_unguarded');
    });

    it('exhibits every leak the harness exists to catch', function () {
        $id = Str::uuid7()->toString();

        actingAsTenant(TenantFixture::TENANT_A, fn () => DB::table('harness_probes_unguarded')->insert([
            'id' => $id,
            'tenant_id' => TenantFixture::TENANT_A,
            'label' => 'probe',
        ]));

        $leakedSelect = actingAsTenant(
            TenantFixture::TENANT_B,
            fn () => DB::table('harness_probes_unguarded')->get(),
        );

        expect($leakedSelect)->toHaveCount(1);

        $leakedUpdate = actingAsTenant(
            TenantFixture::TENANT_B,
            fn () => DB::table('harness_probes_unguarded')->where('id', $id)->update(['label' => 'tampered']),
        );

        expect($leakedUpdate)->toBe(1);

        $forgedInsert = fn () => actingAsTenant(TenantFixture::TENANT_B, fn () => DB::table('harness_probes_unguarded')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_A,
            'label' => 'forged',
        ]));

        expect($forgedInsert())->toBeTrue();

        $leakedOutside = DB::select('select * from harness_probes_unguarded');

        expect($leakedOutside)->toHaveCount(2);

        $leakedDelete = actingAsTenant(
            TenantFixture::TENANT_B,
            fn () => DB::table('harness_probes_unguarded')->where('id', $id)->delete(),
        );

        expect($leakedDelete)->toBe(1);
    });
});
