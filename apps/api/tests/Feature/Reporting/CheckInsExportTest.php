<?php

use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Jobs\BuildExportJob;
use App\Reporting\Models\Export;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12, TDD sequencing Slice 8, Feature (end to
 * end): "a completed [check_ins] export contains exactly the expected
 * CSV rows for the tenant and parameter window ... row_count and
 * completed_at are set." check_ins is ungated in this run (the
 * attendance projector, task 11, already landed in T7).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('media.protected_disk'));
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'exports', 'check_ins', 'tickets', 'orders', 'ticket_types', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('users')->where('email', 'like', '%check-ins-export-test.example')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * One tenant with an included check-in (target event, inside window), a
 * check-in outside the date window, and a check-in for a different
 * event, all under the same tenant.
 *
 * @return array{tenantId: string, eventId: string, includedCheckInId: string, userId: string}
 */
function checkInsExportFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(
        fn () => User::factory()->create(['email' => 'requester@check-ins-export-test.example'])->id,
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
        $otherOrder = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $otherEvent->id,
            'status' => OrderStatus::Paid,
        ]);

        $includedTicket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]);
        $outsideWindowTicket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]);
        $otherEventTicket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $otherOrder->id,
            'ticket_type_id' => $otherTicketType->id,
            'event_id' => $otherEvent->id,
        ]);

        $included = CheckIn::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $includedTicket->id,
            'event_id' => $event->id,
            'user_id' => $userId,
            'result' => CheckInResult::Accepted,
            'scanned_at' => CarbonImmutable::parse('2026-07-10T12:00:00Z'),
        ]);

        CheckIn::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $outsideWindowTicket->id,
            'event_id' => $event->id,
            'user_id' => $userId,
            'result' => CheckInResult::Accepted,
            'scanned_at' => CarbonImmutable::parse('2026-06-01T12:00:00Z'),
        ]);

        CheckIn::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $otherEventTicket->id,
            'event_id' => $otherEvent->id,
            'user_id' => $userId,
            'result' => CheckInResult::Accepted,
            'scanned_at' => CarbonImmutable::parse('2026-07-11T12:00:00Z'),
        ]);

        return [
            'tenantId' => $tenantId,
            'eventId' => $event->id,
            'includedCheckInId' => $included->id,
            'includedTicketId' => $includedTicket->id,
            'userId' => $userId,
        ];
    });
}

/**
 * @return list<list<string>>
 */
function readCheckInsExportCsv(Export $export): array
{
    $path = $export->getMedia('export_file')->first()->getPathRelativeToRoot();
    $contents = Storage::disk(config()->string('media.protected_disk'))->get($path);

    $lines = array_filter(explode("\n", trim($contents)));

    return array_map(str_getcsv(...), $lines);
}

it('completes a check_ins export containing exactly the CSV rows for the tenant, event, and date window', function (): void {
    $fixture = checkInsExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'type' => ExportType::CheckIns,
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

        expect(readCheckInsExportCsv($export))->toBe([
            ['id', 'event_id', 'ticket_id', 'result', 'scanned_at'],
            [
                $fixture['includedCheckInId'], $fixture['eventId'], $fixture['includedTicketId'],
                'accepted', '2026-07-10T12:00:00Z',
            ],
        ]);
    });
});
