<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * gateway_webhook_events is the platform-scoped raw webhook log
 * (stage-08a plan, Data model): a webhook arrives with no tenant
 * context, so every row carries the sentinel platform tenant
 * (data-conventions Tenancy: platform-scope rows use the sentinel,
 * never NULL). The sentinel-tenant table still ships its policy and its
 * isolation coverage: ordinary tenants must see and write nothing here.
 */

/**
 * @return array<string, mixed>
 */
function validWebhookEventRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => config()->string('tenancy.platform_tenant_id'),
        'gateway' => 'fake',
        'gateway_event_id' => 'evt_'.Str::uuid7()->toString(),
        'payload' => json_encode(['type' => 'payment.confirmed']),
        'status' => 'received',
        'received_at' => now(),
        'processed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(function (): void {
    TenantFixture::seed();

    $this->sentinel = config()->string('tenancy.platform_tenant_id');

    $this->rowId = validWebhookEventRow()['id'];

    actingAsRole(Rls::APP_ROLE, $this->sentinel, function (): void {
        DB::table('gateway_webhook_events')->insert(validWebhookEventRow(['id' => $this->rowId]));
    });
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, $this->sentinel, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    TenantFixture::clean();
});

it('shows an ordinary tenant no raw webhook rows', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('gateway_webhook_events')->pluck('id'),
    );

    expect($ids->all())->toBe([]);
});

it('rejects a tenant insert bearing the sentinel tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('gateway_webhook_events')->insert(validWebhookEventRow()),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('gateway_webhook_events')->where('id', $this->rowId)->update(['status' => 'processed']),
    );

    expect($affected)->toBe(0);
});

it('admits reads and writes under the sentinel tenant context', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        $this->sentinel,
        fn () => DB::table('gateway_webhook_events')->pluck('id'),
    );

    expect($ids->all())->toContain($this->rowId);
});

it('lets nodia_platform read the raw log without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('gateway_webhook_events')->pluck('id'));

    expect($ids->all())->toContain($this->rowId);
});
