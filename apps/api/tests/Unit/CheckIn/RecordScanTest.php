<?php

use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\RecordScanData;
use App\CheckIn\Exceptions\CheckinNotAssignedException;
use App\CheckIn\Models\CheckIn;
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
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 4 unit rule: RecordScan records TicketCheckedIn
 * in the same transaction as the accepted insert, proven by rolling
 * back and finding neither row nor event; and the checkin.manage
 * authorization bypass works the same way it does for the manifest and
 * signing-key surfaces.
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
function unitScanFixture(string $tenantId): array
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

function unitScanPayload(string $ticketId, string $eventId, int $rotation = 0, string $secret = 'unit-secret'): string
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

function unitScanManager(string $tenantId): string
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

it('records the accepted row and TicketCheckedIn in the same transaction, gone on rollback', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitScanFixture($this->tenantId);
    $userId = unitScanManager($this->tenantId);
    $payload = unitScanPayload($ticketId, $eventId);

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($payload, $userId): void {
            (app(RecordScan::class))(new RecordScanData($payload, 'device-1', (string) Str::uuid7(), now()->toIso8601String()), $userId);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    $leftovers = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => [
            'checkIns' => CheckIn::query()->where('ticket_id', $ticketId)->count(),
            'events' => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'TicketCheckedIn')->count(),
        ],
    );

    expect($leftovers)->toBe(['checkIns' => 0, 'events' => 0]);
});

it('authorizes a checkin.manage caller with no assignment row', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitScanFixture($this->tenantId);
    $userId = unitScanManager($this->tenantId);
    $payload = unitScanPayload($ticketId, $eventId);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RecordScan::class))(new RecordScanData($payload, 'device-1', (string) Str::uuid7(), now()->toIso8601String()), $userId),
    );

    expect($result->wasReplay)->toBeFalse()
        ->and($result->data->result->value)->toBe('accepted');
});

it('denies an unauthorized caller replaying a stored scan instead of leaking the stored result', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitScanFixture($this->tenantId);
    $manager = unitScanManager($this->tenantId);
    $payload = unitScanPayload($ticketId, $eventId);
    $deviceId = 'device-1';
    $clientScanId = (string) Str::uuid7();

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RecordScan::class))(new RecordScanData($payload, $deviceId, $clientScanId, now()->toIso8601String()), $manager),
    );

    $intruder = User::factory()->create();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($payload, $deviceId, $clientScanId, $intruder): void {
        expect(fn () => (app(RecordScan::class))(new RecordScanData($payload, $deviceId, $clientScanId, now()->toIso8601String()), $intruder->id))
            ->toThrow(CheckinNotAssignedException::class);
    });
});

it('denies a caller with no checkin capability at all', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = unitScanFixture($this->tenantId);
    $user = User::factory()->create();
    $payload = unitScanPayload($ticketId, $eventId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($payload, $user): void {
        expect(fn () => (app(RecordScan::class))(new RecordScanData($payload, 'device-1', (string) Str::uuid7(), now()->toIso8601String()), $user->id))
            ->toThrow(CheckinNotAssignedException::class);
    });
});
