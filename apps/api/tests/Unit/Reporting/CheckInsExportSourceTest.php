<?php

use App\CheckIn\Actions\PaginateCheckInsForExport;
use App\CheckIn\Data\CheckInExportRowData;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Reporting\Support\Export\Sources\CheckInsExportSource;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "one unit test per source in
 * tests/Unit/Reporting/ mapping Data objects to CSV columns and proving
 * the cursor iterator pages rather than loading the whole set."
 */

it('maps check-in export rows to their CSV columns', function (): void {
    $source = new CheckInsExportSource(new PaginateCheckInsForExport);

    $row = new CheckInExportRowData(
        id: 'check-in-1',
        eventId: 'event-1',
        ticketId: 'ticket-1',
        result: 'accepted',
        scannedAt: '2026-07-10T12:00:00Z',
    );

    $columns = $source->columns();

    expect(array_map(fn (callable $extract) => $extract($row), $columns))->toBe([
        'id' => 'check-in-1',
        'event_id' => 'event-1',
        'ticket_id' => 'ticket-1',
        'result' => 'accepted',
        'scanned_at' => '2026-07-10T12:00:00Z',
    ]);
});

it('pages check-ins through PaginateCheckInsForExport without loading the whole result set', function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): void {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $ticket = Ticket::factory()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'ticket_type_id' => Str::uuid7()->toString(),
                'event_id' => $event->id,
            ]);

            CheckIn::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'event_id' => $event->id,
                'user_id' => $userId,
                'result' => CheckInResult::Accepted,
            ]);
        }

        $source = new CheckInsExportSource(new PaginateCheckInsForExport(perPage: 2));

        DB::enableQueryLog();
        $pages = $source->pages($tenantId, ['event_id' => $event->id]);

        expect(DB::getQueryLog())->toHaveCount(0);

        $pages->current();
        expect(DB::getQueryLog())->toHaveCount(1)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(2)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(3)
            ->and($pages->current())->toHaveCount(1);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(3)
            ->and($pages->valid())->toBeFalse();
    });

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        foreach (['check_ins', 'tickets', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function () use ($tenantId, $userId): void {
        DB::table('users')->where('id', $userId)->delete();
        Tenant::query()->whereKey($tenantId)->delete();
    });
});
