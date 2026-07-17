<?php

use App\EventCatalog\Actions\CreateTicketType;
use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 8: CreateTicketType Action unit
 * coverage, mirroring tests/Unit/EventCatalog/CreateEventTest.php's own
 * structure, plus the boundary call to
 * App\Tenancy\Actions\ResolveTenantSettlementCurrency this task adds.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['settlement_currency' => 'USD'])->id,
    );

    $this->event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        // outbox_deliveries before outbox_events: App\EventCatalog\Jobs\
        // RefreshSearchIndex now subscribes to EventUpdated (stage-05c
        // plan, task breakdown item 7), and CreateTicketType records that
        // type for its parent event, so a delivery row exists here and its
        // outbox_event_id foreign key blocks the parent delete otherwise,
        // mirroring tests/Feature/EventCatalog/
        // TicketTypeEventUpdatedOutboxTest.php's own cleanup order.
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('creates a ticket type scoped to the parent event and tenant, returning its TicketTypeData', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    expect($result)->toBeInstanceOf(TicketTypeData::class)
        ->and(Str::isUuid($result->id))->toBeTrue()
        ->and($result->tenantId)->toBe($this->tenantId)
        ->and($result->eventId)->toBe($this->event->id)
        ->and($result->name)->toBe('General Admission')
        ->and($result->price->amount)->toBe(5000)
        ->and($result->price->currency)->toBe('USD')
        ->and($result->requiresSeat)->toBeFalse()
        ->and($result->maxPerCustomer)->toBeNull();

    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->find($result->id),
    );

    expect($ticketType)->not->toBeNull()
        ->and($ticketType->tenant_id)->toBe($this->tenantId);
});

it('persists an explicit max_per_customer', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'max_per_customer' => 4,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    expect($result->maxPerCustomer)->toBe(4);

    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->find($result->id),
    );

    expect($ticketType->max_per_customer)->toBe(4);
});

it('defaults requires_seat to false when absent from the payload', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    expect($result->requiresSeat)->toBeFalse();
});

it('throws CurrencyMismatchException when the price currency differs from the tenant settlement currency', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'EUR'],
        'sales_start' => null,
        'sales_end' => null,
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    expect($invoke)->toThrow(CurrencyMismatchException::class);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->where('tenant_id', $this->tenantId)->count(),
    );

    expect($count)->toBe(0);
});

it('records an EventUpdated outbox row for the parent event in the producing transaction', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ]);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->where('aggregate_id', $this->event->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $this->event->id]);
});

it('seeds the ticket_type_inventory counter row with the given quantity for a GA ticket type', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'quantity' => 250,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $result->id)->first(),
    );

    expect($inventory)->not->toBeNull()
        ->and($inventory->quantity)->toBe(250)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->sold)->toBe(0);
});

it('seeds a zero-quantity counter row for a GA ticket type given no quantity', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $result->id)->first(),
    );

    expect($inventory)->not->toBeNull()->and($inventory->quantity)->toBe(0);
});

it('does not seed a counter row for a requires_seat ticket type', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'Reserved',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $result->id)->first(),
    );

    expect($inventory)->toBeNull();
});

it('seeds a zero-quantity counter row for a requires_seat ticket type added to a published event', function () {
    $publishedEvent = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'status' => 'published',
        ]),
    );

    $data = CreateTicketTypeData::from([
        'name' => 'Reserved',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($publishedEvent, $data),
    );

    $inventory = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $result->id)->first(),
    );

    expect($inventory)->not->toBeNull()
        ->and($inventory->quantity)->toBe(0)
        ->and($inventory->held)->toBe(0)
        ->and($inventory->sold)->toBe(0);
});

it('throws a validation exception for a quantity given on a requires_seat ticket type', function () {
    $data = CreateTicketTypeData::from([
        'name' => 'Reserved',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
        'quantity' => 100,
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateTicketType::class)($this->event, $data),
    );

    expect($invoke)->toThrow(ValidationException::class);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->where('tenant_id', $this->tenantId)->count(),
    );

    expect($count)->toBe(0);
});
