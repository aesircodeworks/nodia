<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 7: model with the Stage 1 Money cast
 * for price and factory. DB-backed (unlike a pure Data-object test)
 * because the price_amount/currency round-trip through MoneyCast and the
 * requires_seat DEFAULT are both properties of the creating migration and
 * the cast, not pure PHP, mirroring EventModelTest's own precedent for
 * exercising a model through a real tenant transaction.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function (): void {
            TicketType::query()->where('tenant_id', $this->tenantId)->delete();
            Event::query()->where('tenant_id', $this->tenantId)->delete();
        },
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('round-trips price through the MoneyCast onto price_amount and currency', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'price' => Money::of(12500, 'BRL'),
        ]),
    );

    $raw = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (array) TicketType::query()->getConnection()->table('ticket_types')->find($ticketType->id),
    );

    expect((int) $raw['price_amount'])->toBe(12500)
        ->and($raw['currency'])->toBe('BRL');

    $fresh = $ticketType->fresh();

    expect($fresh->price)->toBeInstanceOf(Money::class)
        ->and($fresh->price->equals(Money::of(12500, 'BRL')))->toBeTrue();
});

it('defaults a newly created ticket type to requires_seat false when not supplied', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]),
    );

    expect($ticketType->fresh()->requires_seat)->toBeFalse();
});

it('sets requires_seat true via the requiringSeat factory state', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->requiringSeat()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]),
    );

    expect($ticketType->fresh()->requires_seat)->toBeTrue();
});

it('allows a null sales window by default', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]),
    );

    expect($ticketType->fresh())
        ->sales_start->toBeNull()
        ->sales_end->toBeNull();
});

it('stores and retrieves an explicit sales window', function () {
    $salesStart = now()->addWeek()->startOfSecond();
    $salesEnd = (clone $salesStart)->addMonth();

    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'sales_start' => $salesStart,
            'sales_end' => $salesEnd,
        ]),
    );

    $fresh = $ticketType->fresh();

    expect($fresh->sales_start->equalTo($salesStart))->toBeTrue()
        ->and($fresh->sales_end->equalTo($salesEnd))->toBeTrue();
});

it('resolves its owning event through the event relation', function () {
    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
        ]),
    );

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $ticketType->fresh()->event,
    );

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->id)->toBe($this->eventId);
});
