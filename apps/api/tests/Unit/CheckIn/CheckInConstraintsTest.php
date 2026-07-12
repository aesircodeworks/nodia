<?php

use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 1: the result enum column and the two
 * database-level constraints backstopping first-scan-wins and replay
 * idempotence, tested before any endpoint exists.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    [$this->eventId, $this->ticketId] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ]);
        $ticket = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => Str::uuid7()->toString(),
            'event_id' => $event->id,
        ]);

        return [$event->id, $ticket->id];
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_ins')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('users')->where('id', $this->userId)->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function acceptedCheckIn(string $tenantId, string $ticketId, string $eventId, string $userId, array $overrides = []): CheckIn
{
    return CheckIn::factory()->create([
        'tenant_id' => $tenantId,
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'user_id' => $userId,
        'result' => CheckInResult::Accepted,
        ...$overrides,
    ]);
}

it('casts a valid result value to the CheckInResult enum', function (): void {
    $checkIn = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId),
    );

    expect($checkIn->result)->toBe(CheckInResult::Accepted);
});

it('rejects a result value outside the enum when the model reads the row back', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_ins')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $this->tenantId,
            'ticket_id' => $this->ticketId,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'device_id' => 'device-1',
            'client_scan_id' => Str::uuid7()->toString(),
            'result' => 'bogus',
            'scanned_at' => now(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => CheckIn::query()->first()->result,
    ))->toThrow(ValueError::class);
});

it('rejects a second accepted check-in for the same ticket through the partial unique index', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId),
    ))->toThrow(QueryException::class, 'check_ins_accepted_ticket_idx');
});

it('allows a second duplicate check-in for the same ticket alongside the accepted one', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId),
    );

    $duplicate = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId, [
            'result' => CheckInResult::Duplicate,
        ]),
    );

    expect($duplicate->result)->toBe(CheckInResult::Duplicate);
});

it('rejects a replayed (device_id, client_scan_id) pair for the same tenant through the unique index', function (): void {
    $clientScanId = Str::uuid7()->toString();

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId, [
            'device_id' => 'device-1',
            'client_scan_id' => $clientScanId,
        ]),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => acceptedCheckIn($this->tenantId, $this->ticketId, $this->eventId, $this->userId, [
            'device_id' => 'device-1',
            'client_scan_id' => $clientScanId,
            'result' => CheckInResult::Duplicate,
        ]),
    ))->toThrow(QueryException::class);
});
