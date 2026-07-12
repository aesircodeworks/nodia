<?php

use App\CheckIn\Actions\ReconcileOfflineScans;
use App\CheckIn\Data\BatchResultData;
use App\CheckIn\Data\OfflineScanData;
use App\CheckIn\Data\ReconcileBatchData;
use App\CheckIn\Enums\ScanOutcome;
use App\CheckIn\Exceptions\BatchTooLargeException;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 5 unit rule: the resolution routine in isolation
 * -- demote-then-insert conditional UPDATE checked by affected-row
 * count, retry-on-conflict against the partial unique index, and
 * tie-break determinism.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('check_ins')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_signing_keys')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

/**
 * @return array{eventId: string, ticketId: string}
 */
function unitBatchFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'hold_id' => Str::uuid7()->toString(),
        ]);
        $ticket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]);
        EventSigningKey::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'key_version' => 1,
            'status' => SigningKeyStatus::Active,
            'secret' => 'unit-secret',
        ]);

        return ['eventId' => $event->id, 'ticketId' => $ticket->id];
    });
}

function unitBatchPayload(string $ticketId, string $eventId, int $rotation = 0, string $secret = 'unit-secret'): string
{
    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|'.$rotation, $secret);

    $body = json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => $rotation,
        'signature' => $signature,
    ]);

    return rtrim(strtr(base64_encode((string) $body), '+/', '-_'), '=');
}

function unitBatchManager(string $tenantId): string
{
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create([
                'tenant_id' => $tenantId,
                'capabilities' => [Capability::CheckinManage->value],
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $user->id;
}

it('demotes the currently accepted row and swaps in an earlier-timestamped scan', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitBatchFixture($this->tenantId);
    $userId = unitBatchManager($this->tenantId);
    $payload = unitBatchPayload($ticketId, $eventId);

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($payload, $userId): BatchResultData {
        $batch = new ReconcileBatchData('device-later', [
            new OfflineScanData((string) Str::uuid7(), $payload, now()->subMinutes(5)->toIso8601String()),
        ]);
        (app(ReconcileOfflineScans::class))($batch, $userId);

        $swap = new ReconcileBatchData('device-earlier', [
            new OfflineScanData((string) Str::uuid7(), $payload, now()->subMinutes(10)->toIso8601String()),
        ]);

        return (app(ReconcileOfflineScans::class))($swap, $userId);
    });

    expect($result->results[0]->outcome)->toBe(ScanOutcome::Accepted);

    [$acceptedDevice, $duplicateCount] = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => [
        DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'accepted')->value('device_id'),
        DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'duplicate')->count(),
    ]);
    expect($acceptedDevice)->toBe('device-earlier')->and($duplicateCount)->toBe(1);
});

it('breaks equal-timestamp ties deterministically by smallest client_scan_id', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitBatchFixture($this->tenantId);
    $userId = unitBatchManager($this->tenantId);
    $payload = unitBatchPayload($ticketId, $eventId);
    $scannedAt = now()->subMinutes(5)->toIso8601String();
    $smaller = '00000000-0000-7000-8000-000000000001';
    $larger = 'ffffffff-ffff-7fff-bfff-ffffffffffff';

    $winnerDevice = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($payload, $userId, $scannedAt, $smaller, $larger, $ticketId): string {
        (app(ReconcileOfflineScans::class))(new ReconcileBatchData('device-larger', [
            new OfflineScanData($larger, $payload, $scannedAt),
        ]), $userId);

        (app(ReconcileOfflineScans::class))(new ReconcileBatchData('device-smaller', [
            new OfflineScanData($smaller, $payload, $scannedAt),
        ]), $userId);

        return (string) DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'accepted')->value('device_id');
    });

    expect($winnerDevice)->toBe('device-smaller');
});

it('rejects a batch of more than 500 scans before touching any row', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitBatchFixture($this->tenantId);
    $userId = unitBatchManager($this->tenantId);
    $payload = unitBatchPayload($ticketId, $eventId);

    $scans = [];
    for ($i = 0; $i < 501; $i++) {
        $scans[] = new OfflineScanData((string) Str::uuid7(), $payload, now()->toIso8601String());
    }

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($scans, $userId): void {
        expect(fn () => (app(ReconcileOfflineScans::class))(new ReconcileBatchData('device-1', $scans), $userId))
            ->toThrow(BatchTooLargeException::class);
    });

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
    );
    expect($rows)->toBe(0);
});
