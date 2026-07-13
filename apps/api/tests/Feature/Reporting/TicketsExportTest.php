<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\Ticket;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Jobs\BuildExportJob;
use App\Reporting\Models\Export;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12, TDD sequencing Slice 8, Feature (end to
 * end): "a completed [tickets] export contains exactly the expected CSV
 * rows for the tenant and parameter window ... row_count and
 * completed_at are set", plus T12's own mandate that the tickets and
 * ledger_entries sources are also proven never to leak another tenant's
 * rows.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'exports', 'tickets', 'order_items', 'orders', 'ticket_types', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('users')->where('email', 'like', '%tickets-export-test.example')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * One tenant with an included ticket (target event, inside window, with
 * attendee_name and a priced order_item), a ticket outside the date
 * window, and a ticket for a different event, all under the same
 * tenant.
 *
 * @return array{tenantId: string, eventId: string, includedTicketId: string, userId: string}
 */
function ticketsExportFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(
        fn () => User::factory()->create(['email' => 'requester-'.Str::uuid7()->toString().'@tickets-export-test.example'])->id,
    );

    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $otherEvent = Event::factory()->create(['tenant_id' => $tenantId]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $otherTicketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $otherEvent->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);
        OrderItem::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'unit_price' => Money::of(3500, 'USD'),
        ]);

        $included = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            'status' => TicketStatus::Issued,
            'attendee_name' => 'Jane Doe',
            'issued_at' => CarbonImmutable::parse('2026-07-10T12:00:00Z'),
        ]);

        Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            'issued_at' => CarbonImmutable::parse('2026-06-01T12:00:00Z'),
        ]);

        $otherOrder = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $otherEvent->id,
            'status' => OrderStatus::Paid,
        ]);

        Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $otherOrder->id,
            'ticket_type_id' => $otherTicketType->id,
            'event_id' => $otherEvent->id,
            'issued_at' => CarbonImmutable::parse('2026-07-11T12:00:00Z'),
        ]);

        return [
            'tenantId' => $tenantId,
            'eventId' => $event->id,
            'includedTicketId' => $included->id,
            'userId' => $userId,
        ];
    });
}

/**
 * @return list<list<string>>
 */
function readTicketsExportCsv(Export $export): array
{
    $path = $export->getMedia('export_file')->first()->getPathRelativeToRoot();
    $contents = Storage::disk('media')->get($path);

    $lines = array_filter(explode("\n", trim($contents)));

    return array_map(str_getcsv(...), $lines);
}

it('completes a tickets export containing exactly the CSV rows for the tenant, event, and date window', function (): void {
    $fixture = ticketsExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'type' => ExportType::Tickets,
        'requested_by_user_id' => $fixture['userId'],
        'parameters' => [
            'event_id' => $fixture['eventId'],
            'from' => '2026-07-01T00:00:00Z',
            'to' => '2026-07-31T23:59:59Z',
        ],
    ])->id);

    BuildExportJob::dispatch($exportId);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($exportId, $fixture): void {
        $export = Export::query()->findOrFail($exportId);

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->row_count)->toBe(1)
            ->and($export->completed_at)->not->toBeNull()
            ->and($export->failure_code)->toBeNull();

        expect(readTicketsExportCsv($export))->toBe([
            ['id', 'event_id', 'ticket_type_id', 'order_id', 'status', 'attendee_name', 'list_price_amount', 'list_price_currency', 'issued_at'],
            [
                $fixture['includedTicketId'], $fixture['eventId'],
                Ticket::query()->findOrFail($fixture['includedTicketId'])->ticket_type_id,
                Ticket::query()->findOrFail($fixture['includedTicketId'])->order_id,
                'issued', 'Jane Doe', '3500', 'USD', '2026-07-10T12:00:00Z',
            ],
        ]);
    });
});

it('never includes another tenant\'s tickets', function (): void {
    $fixtureA = ticketsExportFixture();
    $fixtureB = ticketsExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixtureA['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixtureA['tenantId'],
        'type' => ExportType::Tickets,
        'requested_by_user_id' => $fixtureA['userId'],
        'parameters' => [
            'event_id' => $fixtureA['eventId'],
            'from' => '2026-07-01T00:00:00Z',
            'to' => '2026-07-31T23:59:59Z',
        ],
    ])->id);

    BuildExportJob::dispatch($exportId);

    app(TenantTransaction::class)->asTenant($fixtureA['tenantId'], function () use ($exportId, $fixtureA, $fixtureB): void {
        $export = Export::query()->findOrFail($exportId);

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->row_count)->toBe(1);

        $rows = readTicketsExportCsv($export);
        $ids = array_column(array_slice($rows, 1), 0);

        expect($ids)->toBe([$fixtureA['includedTicketId']])
            ->and($ids)->not->toContain($fixtureB['includedTicketId']);
    });
});
