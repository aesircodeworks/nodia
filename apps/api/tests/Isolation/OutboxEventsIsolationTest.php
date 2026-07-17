<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\OutboxEventFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * outbox_events is the standard Rls::applyTenantPolicies posture with no
 * platform write policy (stage-04 plan Data model / Slice 1 isolation):
 * tenant A cannot read or write tenant B's rows under nodia_app; the
 * cross-tenant nodia_platform role can read both. Append-only is an
 * application invariant on the OutboxEvent model, not a grant restriction,
 * so the deny matrix still covers UPDATE and DELETE the way every other
 * standard-policy table does. The owning-tenant insert case also proves
 * the sequence identity column is writable under the app role with RLS
 * enabled (stage-04 plan risk: "Sequence identity versus RLS inserts").
 */

beforeEach(fn () => OutboxEventFixture::seed());

afterEach(fn () => OutboxEventFixture::clean());

it('shows a tenant only its own outbox_events rows', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_events')->pluck('id'),
    );

    expect($ids->all())->toBe([OutboxEventFixture::EVENT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_events')
            ->where('id', OutboxEventFixture::EVENT_B)
            ->update(['type' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $type = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('outbox_events')->where('id', OutboxEventFixture::EVENT_B)->value('type'),
    );

    expect($type)->toBe('TestEvent');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_events')->where('id', OutboxEventFixture::EVENT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('outbox_events')->where('id', OutboxEventFixture::EVENT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_events')->insert([
            'id' => Str::uuid7()->toString(),
            'type' => 'TestEvent',
            'tenant_id' => TenantFixture::TENANT_B,
            'aggregate_type' => 'test_aggregate',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'forged-correlation',
            'occurred_at' => now(),
            'payload' => json_encode(['source' => 'forged'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from outbox_events'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(OutboxEventFixture::EVENT_A);
});

it('lets nodia_platform read every tenant outbox_events without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('outbox_events')->pluck('id'));

    expect($ids->all())->toContain(OutboxEventFixture::EVENT_A, OutboxEventFixture::EVENT_B);
});

it('rejects a nodia_platform write with no tenant asserted, outbox_events has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('outbox_events')
            ->where('id', OutboxEventFixture::EVENT_A)
            ->update(['type' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});

it('lets the owning tenant insert and assigns a sequence identity', function () {
    $id = Str::uuid7()->toString();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($id): void {
        DB::table('outbox_events')->insert([
            'id' => $id,
            'type' => 'TestEvent',
            'tenant_id' => TenantFixture::TENANT_A,
            'aggregate_type' => 'test_aggregate',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'owning-tenant-insert',
            'occurred_at' => now(),
            'payload' => json_encode(['source' => 'owning-insert'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_events')->where('id', $id)->first(),
    );

    expect($row)->not->toBeNull()
        ->and($row->sequence)->toBeInt()
        ->and($row->sequence)->toBeGreaterThan(0);
});
