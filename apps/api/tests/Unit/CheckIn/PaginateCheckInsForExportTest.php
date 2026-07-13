<?php

use App\CheckIn\Actions\PaginateCheckInsForExport;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "plus unit coverage of the three new
 * owning-context Actions in their own suites." Mirrors
 * PaginateOrdersForExport's own filter matrix, over CheckIn.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->tenantId, $this->userId, $this->eventId, $this->otherEventId, $this->orderId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
        $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId]);
            $otherEvent = Event::factory()->create(['tenant_id' => $tenantId]);
            $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
            $order = Order::factory()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'status' => OrderStatus::Paid,
            ]);

            return [$tenantId, $userId, $event->id, $otherEvent->id, $order->id];
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

function checkInExportTicket(string $tenantId, string $orderId, string $eventId): Ticket
{
    return Ticket::factory()->create([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'ticket_type_id' => Str::uuid7()->toString(),
        'event_id' => $eventId,
    ]);
}

it('filters by event_id and returns rows only for the matching event', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $ticket = checkInExportTicket($this->tenantId, $this->orderId, $this->eventId);
        $matching = CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
        ]);

        $otherTicket = checkInExportTicket($this->tenantId, $this->orderId, $this->otherEventId);
        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $otherTicket->id,
            'event_id' => $this->otherEventId,
            'user_id' => $this->userId,
        ]);

        $paginate = new PaginateCheckInsForExport;
        $rows = collect($paginate($this->eventId, null, null))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBeInstanceOf(CheckInExportRowData::class)
            ->and($rows->first()->id)->toBe($matching->id);
    });
});

it('bounds scanned_at inclusively on both ends', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $ticket = checkInExportTicket($this->tenantId, $this->orderId, $this->eventId);
        $ticket2 = checkInExportTicket($this->tenantId, $this->orderId, $this->eventId);
        $ticket3 = checkInExportTicket($this->tenantId, $this->orderId, $this->eventId);

        $inside = CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'scanned_at' => CarbonImmutable::parse('2026-07-10T12:00:00Z'),
        ]);

        $before = CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket2->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'scanned_at' => CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        ]);

        $after = CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket3->id,
            'event_id' => $this->eventId,
            'user_id' => $this->userId,
            'scanned_at' => CarbonImmutable::parse('2026-08-01T00:00:00Z'),
        ]);

        $paginate = new PaginateCheckInsForExport;
        $ids = collect($paginate(null, '2026-07-01T00:00:00Z', '2026-07-31T23:59:59Z'))
            ->flatten(1)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$inside->id])
            ->and($ids)->not->toContain($before->id)
            ->and($ids)->not->toContain($after->id);
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $tenantId = $this->tenantId;
        $orderId = $this->orderId;
        $eventId = $this->eventId;
        $userId = $this->userId;

        $checkIns = collect(range(1, 5))->map(function () use ($tenantId, $orderId, $eventId, $userId) {
            $ticket = checkInExportTicket($tenantId, $orderId, $eventId);

            return CheckIn::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'event_id' => $eventId,
                'user_id' => $userId,
            ]);
        });

        $paginate = new PaginateCheckInsForExport(perPage: 2);
        $pages = iterator_to_array($paginate($this->eventId, null, null));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($checkIns->pluck('id')->sort()->values()->all());
    });
});
