<?php

use App\EventCatalog\Actions\CreateTicketType;
use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
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
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
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
        ->and($result->requiresSeat)->toBeFalse();

    $ticketType = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketType::query()->find($result->id),
    );

    expect($ticketType)->not->toBeNull()
        ->and($ticketType->tenant_id)->toBe($this->tenantId);
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
