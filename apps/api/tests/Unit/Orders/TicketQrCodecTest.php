<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Orders\Support\TicketQrCodec;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 4: sign and verify round-trip;
 * tampered payload rejected; payload for a different event's key
 * rejected; after a rotation-counter bump the old payload verifies
 * false and a fresh render verifies true; codec output is deterministic
 * for fixed inputs.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
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
});

function qrTicket(string $tenantId, ?string $eventId = null): Ticket
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId): Ticket {
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

        return Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $eventId ?? $event->id,
        ]);
    });
}

it('round-trips sign and verify', function (): void {
    $ticket = qrTicket($this->tenantId);

    $codec = app(TicketQrCodec::class);
    $payload = $codec->sign($ticket);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->verify($payload),
    );

    expect($result->valid)->toBeTrue()
        ->and($result->ticketId)->toBe($ticket->id);
});

it('is deterministic for fixed inputs', function (): void {
    $ticket = qrTicket($this->tenantId);

    $codec = app(TicketQrCodec::class);

    expect($codec->sign($ticket))->toBe($codec->sign($ticket));
});

it('rejects a tampered payload', function (): void {
    $ticket = qrTicket($this->tenantId);
    $other = qrTicket($this->tenantId);

    $codec = app(TicketQrCodec::class);

    $decoded = json_decode(base64_decode(strtr($codec->sign($ticket), '-_', '+/')), true);
    $decoded['ticket_id'] = $other->id;
    $tampered = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->verify($tampered),
    );

    expect($result->valid)->toBeFalse();

    $garbage = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->verify('not-a-payload'),
    );

    expect($garbage->valid)->toBeFalse();
});

it('rejects a payload signed with a different event\'s key', function (): void {
    $ticket = qrTicket($this->tenantId);
    $otherEvent = qrTicket($this->tenantId);

    // The same ticket facts signed under a foreign event's derived key:
    // the signature cannot verify against the ticket's own event key.
    $cross = $ticket->replicate();
    $cross->id = $ticket->id;
    $cross->event_id = $otherEvent->event_id;

    $codec = app(TicketQrCodec::class);
    $payload = $codec->sign($cross);

    $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $decoded['event_id'] = $ticket->event_id;
    $reframed = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->verify($reframed),
    );

    expect($result->valid)->toBeFalse();
});

it('invalidates old payloads after a rotation bump and verifies a fresh render', function (): void {
    $ticket = qrTicket($this->tenantId);

    $codec = app(TicketQrCodec::class);
    $before = $codec->sign($ticket);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticket): void {
        DB::table('tickets')->where('id', $ticket->id)->update(['qr_rotation_counter' => 1]);
    });

    $stale = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->verify($before),
    );

    expect($stale->valid)->toBeFalse();

    $fresh = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($codec, $ticket) {
        $reloaded = Ticket::query()->findOrFail($ticket->id);
        $payload = $codec->sign($reloaded);

        return $codec->verify($payload);
    });

    $freshPayload = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $codec->sign(Ticket::query()->findOrFail($ticket->id)),
    );

    expect($fresh->valid)->toBeTrue()
        ->and($freshPayload)->not->toBe($before);
});
