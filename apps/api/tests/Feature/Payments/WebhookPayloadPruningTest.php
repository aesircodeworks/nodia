<?php

use App\Payments\Actions\PruneWebhookPayloads;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Payments\Gateways\FakeGateway;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 3, task breakdown item 7: the webhook payload
 * pruner. Nulls gateway_webhook_events.payload and stamps
 * payload_pruned_at for rows past config('retention.webhook_payload_days'),
 * leaves younger rows untouched, and never breaks the (gateway,
 * gateway_event_id) idempotence anchor a duplicate delivery relies on
 * after pruning (system-design 14.3).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->delete(),
    );
});

/**
 * @return array{id: string, gatewayEventId: string}
 */
function insertPruningWebhookRow(string $receivedAt, array $overrides = []): array
{
    $id = (string) Str::uuid7();
    $gatewayEventId = 'evt_'.Str::uuid7();

    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        function () use ($id, $gatewayEventId, $receivedAt, $overrides): void {
            DB::table('gateway_webhook_events')->insert([
                'id' => $id,
                'tenant_id' => config()->string('tenancy.platform_tenant_id'),
                'gateway' => 'fake',
                'gateway_event_id' => $gatewayEventId,
                'payload' => json_encode(['type' => 'payment.confirmed']),
                'status' => 'processed',
                'received_at' => $receivedAt,
                'processed_at' => $receivedAt,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
                ...$overrides,
            ]);
        },
    );

    return ['id' => $id, 'gatewayEventId' => $gatewayEventId];
}

function fetchPruningWebhookRow(string $id): object
{
    return app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->where('id', $id)->firstOrFail(),
    );
}

it('nulls payloads and stamps payload_pruned_at for rows past the window, leaving young rows untouched', function (): void {
    $this->freezeTime();
    $now = now();

    $old = insertPruningWebhookRow($now->copy()->subDays(91)->toDateTimeString());
    $young = insertPruningWebhookRow($now->copy()->subDays(10)->toDateTimeString());

    $pruned = app(PruneWebhookPayloads::class)();

    expect($pruned)->toBe(1);

    $oldRow = fetchPruningWebhookRow($old['id']);
    expect($oldRow->payload)->toBeNull()
        ->and($oldRow->payload_pruned_at)->not->toBeNull();

    $youngRow = fetchPruningWebhookRow($young['id']);
    expect($youngRow->payload)->not->toBeNull()
        ->and($youngRow->payload_pruned_at)->toBeNull();
});

it('keeps the row and its gateway event id after pruning so a duplicate webhook is still deduplicated', function (): void {
    $this->freezeTime();

    $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref_prune', Money::of(500, 'USD'), 'evt_prune_dup');

    $first = test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body);
    $first->assertStatus(200);

    $rowId = app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->where('gateway_event_id', 'evt_prune_dup')->value('id'),
    );

    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->where('id', $rowId)->update([
            'received_at' => now()->subDays(91),
        ]),
    );

    $pruned = app(PruneWebhookPayloads::class)();
    expect($pruned)->toBe(1);

    $second = test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body);
    $second->assertStatus(200);

    $rowCount = app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->where('gateway_event_id', 'evt_prune_dup')->count(),
    );

    expect($rowCount)->toBe(1);
});

it('is a no-op on rows already pruned when the pruner runs again', function (): void {
    $this->freezeTime();
    $now = now();

    $old = insertPruningWebhookRow($now->copy()->subDays(91)->toDateTimeString());

    $firstRun = app(PruneWebhookPayloads::class)();
    expect($firstRun)->toBe(1);

    $prunedAt = fetchPruningWebhookRow($old['id'])->payload_pruned_at;

    $this->travel(1)->day();

    $secondRun = app(PruneWebhookPayloads::class)();
    expect($secondRun)->toBe(0);

    expect(fetchPruningWebhookRow($old['id'])->payload_pruned_at)->toBe($prunedAt);
});

it('never touches ledger entries while pruning webhook payloads', function (): void {
    $this->freezeTime();
    $now = now();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $entryId = (string) Str::uuid7();
    $sourceEventId = (string) Str::uuid7();
    $referenceId = (string) Str::uuid7();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($entryId, $sourceEventId, $referenceId, $tenantId): void {
        DB::table('ledger_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $tenantId,
            'account' => LedgerAccount::GatewayReceivable->value,
            'direction' => LedgerDirection::Debit->value,
            'amount' => 1_000,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => $referenceId,
            'source_event_id' => $sourceEventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    insertPruningWebhookRow($now->copy()->subDays(91)->toDateTimeString());

    app(PruneWebhookPayloads::class)();

    $stillThere = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')->where('id', $entryId)->first(),
    );

    expect($stillThere)->not->toBeNull()
        ->and((int) $stillThere->amount)->toBe(1_000);

    DB::statement('truncate ledger_entries');
    app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->whereKey($tenantId)->delete());
});
