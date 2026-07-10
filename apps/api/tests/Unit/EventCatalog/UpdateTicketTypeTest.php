<?php

use App\EventCatalog\Actions\UpdateTicketType;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 8: UpdateTicketType Action unit
 * coverage, mirroring tests/Unit/EventCatalog/UpdateEventTest.php's own
 * structure.
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

    $this->ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->event->id,
            'name' => 'Before',
            'price' => Money::of(5000, 'USD'),
        ]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('updates only the given fields, returning the refreshed TicketTypeData', function () {
    $data = UpdateTicketTypeData::from(['name' => 'After']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($result)->toBeInstanceOf(TicketTypeData::class)
        ->and($result->name)->toBe('After')
        ->and($result->price->amount)->toBe(5000)
        ->and($result->price->currency)->toBe('USD');
});

it('updates the price when given', function () {
    $data = UpdateTicketTypeData::from(['price' => ['amount' => 7500, 'currency' => 'USD']]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($result->price->amount)->toBe(7500)
        ->and($result->price->currency)->toBe('USD');
});

it('throws CurrencyMismatchException when the updated price currency differs from the tenant settlement currency', function () {
    $data = UpdateTicketTypeData::from(['price' => ['amount' => 7500, 'currency' => 'EUR']]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    expect($invoke)->toThrow(CurrencyMismatchException::class);

    $stored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->find($this->ticketType->id),
    );

    expect($stored->price->currency)->toBe('USD');
});

it('records an EventUpdated outbox row for the parent event on every successful call', function () {
    $data = UpdateTicketTypeData::from(['name' => 'After']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateTicketType::class)($this->ticketType, $data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->where('aggregate_id', $this->event->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $this->event->id]);
});
