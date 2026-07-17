<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use App\Orders\Exceptions\HoldExpiredException;
use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 1: pricing computation over hold
 * items and ticket type prices in minor units; single-currency
 * invariant across items.
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
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @param  list<array{price: Money, quantity: int}>  $lines
 * @return array{customerId: string, holdId: string, ticketTypeIds: list<string>}
 */
function convertFixture(string $tenantId, array $lines): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $lines): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'convert@example.com']);

        $items = [];
        $ticketTypeIds = [];

        foreach ($lines as $line) {
            $ticketType = TicketType::factory()->create([
                'tenant_id' => $tenantId,
                'event_id' => $event->id,
                'price' => $line['price'],
            ]);

            TicketTypeInventory::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 20,
                'held' => 0,
                'sold' => 0,
            ]);

            $items[] = ['ticket_type_id' => $ticketType->id, 'quantity' => $line['quantity']];
            $ticketTypeIds[] = $ticketType->id;
        }

        $holdId = app(CreateHold::class)(
            CreateHoldData::from(['event_id' => $event->id, 'items' => $items]),
            $customer->id,
        )->id;

        return ['customerId' => $customer->id, 'holdId' => $holdId, 'ticketTypeIds' => $ticketTypeIds];
    });
}

it('prices the order from ticket type prices in integer minor units across multiple lines', function (): void {
    $fixture = convertFixture($this->tenantId, [
        ['price' => Money::of(1250, 'USD'), 'quantity' => 3],
        ['price' => Money::of(9999, 'USD'), 'quantity' => 1],
    ]);

    $order = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $fixture['holdId']]),
            $fixture['customerId'],
        ),
    );

    expect($order->subtotal->amount)->toBe(13749)
        ->and($order->subtotal->currency)->toBe('USD')
        ->and($order->discount->amount)->toBe(0)
        ->and($order->fees->amount)->toBe(0)
        ->and($order->total->amount)->toBe(13749)
        ->and($order->status)->toBe('pending');
});

it('rejects a hold whose items mix currencies', function (): void {
    $fixture = convertFixture($this->tenantId, [
        ['price' => Money::of(1000, 'USD'), 'quantity' => 1],
        ['price' => Money::of(1000, 'EUR'), 'quantity' => 1],
    ]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $fixture['holdId']]),
            $fixture['customerId'],
        ),
    ))->toThrow(CurrencyMismatchException::class);
});

it('refuses an expired hold regardless of sweeper state', function (): void {
    $now = now();
    $this->travelTo($now);

    $fixture = convertFixture($this->tenantId, [
        ['price' => Money::of(1000, 'USD'), 'quantity' => 1],
    ]);

    $this->travelTo($now->copy()->addMinutes(11));

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $fixture['holdId']]),
            $fixture['customerId'],
        ),
    ))->toThrow(HoldExpiredException::class);
});

it('treats a released hold as not found', function (): void {
    $fixture = convertFixture($this->tenantId, [
        ['price' => Money::of(1000, 'USD'), 'quantity' => 1],
    ]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ReleaseHold::class)($fixture['holdId']),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $fixture['holdId']]),
            $fixture['customerId'],
        ),
    ))->toThrow(HoldNotFoundException::class);
});
