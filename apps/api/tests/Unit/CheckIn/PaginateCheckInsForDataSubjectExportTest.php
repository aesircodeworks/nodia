<?php

use App\CheckIn\Actions\PaginateCheckInsForDataSubjectExport;
use App\CheckIn\Data\CheckInExportRowData;
use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 2 Unit: "sources are cursor-paginated per
 * api-conventions' high-volume rule." check_ins carries no customer_id
 * column, so the caller (App\Identity\Actions\BuildDataSubjectExport)
 * always passes in a list of ticket IDs already resolved through
 * Orders; this suite exercises that same contract directly.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->tenantId, $this->userId, $this->eventId, $this->orderId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId]);
            $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
            $order = Order::factory()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'status' => OrderStatus::Paid,
            ]);

            return [$tenantId, $userId, $event->id, $order->id];
        });
    })();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['check_ins', 'tickets', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('users')->where('id', $this->userId)->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function dataSubjectCheckInTicket(string $tenantId, string $orderId, string $eventId): Ticket
{
    return Ticket::factory()->create([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'ticket_type_id' => Str::uuid7()->toString(),
        'event_id' => $eventId,
    ]);
}

it('returns an empty generator for an empty ticket ID list without querying', function (): void {
    $paginate = new PaginateCheckInsForDataSubjectExport;

    expect(iterator_to_array($paginate([])))->toBe([]);
});

it('returns rows only for the given ticket IDs, never another ticket\'s check-in', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $ticket = dataSubjectCheckInTicket($this->tenantId, $this->orderId, $this->eventId);
        $otherTicket = dataSubjectCheckInTicket($this->tenantId, $this->orderId, $this->eventId);

        $matching = CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
        ]);

        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $otherTicket->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
        ]);

        $paginate = new PaginateCheckInsForDataSubjectExport;
        $rows = collect($paginate([$ticket->id]))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBeInstanceOf(CheckInExportRowData::class)
            ->and($rows->first()->id)->toBe($matching->id);
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $tenantId = $this->tenantId;
        $orderId = $this->orderId;
        $eventId = $this->eventId;
        $userId = $this->userId;

        $checkIns = collect(range(1, 5))->map(function () use ($tenantId, $orderId, $eventId, $userId): CheckIn {
            $ticket = dataSubjectCheckInTicket($tenantId, $orderId, $eventId);

            return CheckIn::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'event_id' => $eventId,
                'user_id' => $userId,
            ]);
        });

        $ticketIds = $checkIns->pluck('ticket_id')->all();

        $paginate = new PaginateCheckInsForDataSubjectExport(perPage: 2);
        $pages = iterator_to_array($paginate($ticketIds));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($checkIns->pluck('id')->sort()->values()->all());
    });
});
