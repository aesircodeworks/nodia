<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\OutboxDeliveryFixture;
use Tests\Isolation\Support\OutboxEventFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * outbox_deliveries is the standard Rls::applyTenantPolicies posture with
 * no platform write policy (stage-04 plan Data model / Slice 2 isolation):
 * tenant A cannot read or write tenant B's rows under nodia_app; the
 * cross-tenant nodia_platform role can read both.
 */

beforeEach(fn () => OutboxDeliveryFixture::seed());

afterEach(fn () => OutboxDeliveryFixture::clean());

it('shows a tenant only its own outbox_deliveries rows', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_deliveries')->pluck('id'),
    );

    expect($ids->all())->toBe([OutboxDeliveryFixture::DELIVERY_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_deliveries')
            ->where('id', OutboxDeliveryFixture::DELIVERY_B)
            ->update(['status' => 'processed']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('outbox_deliveries')->where('id', OutboxDeliveryFixture::DELIVERY_B)->value('status'),
    );

    expect($status)->toBe('pending');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_deliveries')->where('id', OutboxDeliveryFixture::DELIVERY_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('outbox_deliveries')->where('id', OutboxDeliveryFixture::DELIVERY_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_deliveries')->insert([
            'id' => Str::uuid7()->toString(),
            'outbox_event_id' => OutboxEventFixture::EVENT_A,
            'tenant_id' => TenantFixture::TENANT_B,
            'subscriber' => 'forged_subscriber',
            'status' => 'pending',
            'processed_at' => null,
            'last_enqueued_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from outbox_deliveries'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(OutboxDeliveryFixture::DELIVERY_A);
});

it('lets nodia_platform read every tenant outbox_deliveries without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('outbox_deliveries')->pluck('id'));

    expect($ids->all())->toContain(OutboxDeliveryFixture::DELIVERY_A, OutboxDeliveryFixture::DELIVERY_B);
});

it('rejects a nodia_platform write with no tenant asserted, outbox_deliveries has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('outbox_deliveries')
            ->where('id', OutboxDeliveryFixture::DELIVERY_A)
            ->update(['status' => 'processed']),
    );

    expect($affected)->toBe(0);
});

it('lets the owning tenant insert a pending delivery for its own event', function () {
    $id = Str::uuid7()->toString();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($id): void {
        DB::table('outbox_deliveries')->insert([
            'id' => $id,
            'outbox_event_id' => OutboxEventFixture::EVENT_A,
            'tenant_id' => TenantFixture::TENANT_A,
            'subscriber' => 'owning_tenant_insert_subscriber',
            'status' => 'pending',
            'processed_at' => null,
            'last_enqueued_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('outbox_deliveries')->where('id', $id)->first(),
    );

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->subscriber)->toBe('owning_tenant_insert_subscriber');
});
