<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Jobs\BuildExportJob;
use App\Reporting\Models\Export;
use App\Reporting\Support\Export\ExportSource;
use App\Reporting\Support\Export\ExportSourceRegistry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, TDD sequencing Slice 8, Feature (end to end): "a
 * completed orders export contains exactly the expected CSV rows for the
 * tenant and parameter window, built through the owning context's
 * cursor-paginated source Action, with money rendered as minor units
 * plus currency columns, never floats; row_count and completed_at are
 * set" and "a failed source marks the export failed with a stable
 * failure_code" (task 15).
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
            foreach (['media', 'exports', 'orders', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('users')->where('email', 'like', '%orders-export-test.example')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * Two orders for the target event inside the requested window and its
 * one included member, one order outside the date window, and one order
 * for a different event inside the same window: exactly one of the four
 * is expected on the CSV.
 *
 * @return array{tenantId: string, eventId: string, includedOrderId: string, userId: string}
 */
function ordersExportFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(
        fn () => User::factory()->create(['email' => 'requester@orders-export-test.example'])->id,
    );

    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $otherEvent = Event::factory()->create(['tenant_id' => $tenantId]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $included = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
            'subtotal' => Money::of(5000, 'USD'),
            'discount' => Money::of(500, 'USD'),
            'fees' => Money::of(200, 'USD'),
            'total' => Money::of(4700, 'USD'),
        ]);
        DB::table('orders')->where('id', $included->id)->update(['created_at' => CarbonImmutable::parse('2026-07-10T12:00:00Z')]);

        $outsideWindow = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);
        DB::table('orders')->where('id', $outsideWindow->id)->update(['created_at' => CarbonImmutable::parse('2026-06-01T12:00:00Z')]);

        $otherEventOrder = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $otherEvent->id,
            'status' => OrderStatus::Paid,
        ]);
        DB::table('orders')->where('id', $otherEventOrder->id)->update(['created_at' => CarbonImmutable::parse('2026-07-11T12:00:00Z')]);

        return [
            'tenantId' => $tenantId,
            'eventId' => $event->id,
            'includedOrderId' => $included->id,
            'userId' => $userId,
        ];
    });
}

/**
 * @return list<list<string>>
 */
function readCsvFromMedia(Export $export): array
{
    $path = $export->getMedia('export_file')->first()->getPathRelativeToRoot();
    $contents = Storage::disk('media')->get($path);

    $lines = array_filter(explode("\n", trim($contents)));

    return array_map(str_getcsv(...), $lines);
}

it('completes an orders export containing exactly the CSV rows for the tenant, event, and date window', function (): void {
    $fixture = ordersExportFixture();

    $exportId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'type' => ExportType::Orders,
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

        $media = $export->getMedia('export_file');

        expect($media)->toHaveCount(1)
            ->and($media->first()->mime_type)->toBe('text/csv');

        expect(readCsvFromMedia($export))->toBe([
            [
                'id', 'event_id', 'status',
                'subtotal_amount', 'subtotal_currency',
                'discount_amount', 'discount_currency',
                'fees_amount', 'fees_currency',
                'total_amount', 'total_currency',
                'created_at',
            ],
            [
                $fixture['includedOrderId'], $fixture['eventId'], 'paid',
                '5000', 'USD',
                '500', 'USD',
                '200', 'USD',
                '4700', 'USD',
                '2026-07-10T12:00:00Z',
            ],
        ]);
    });
});

it('marks the export failed with a stable failure_code and attaches no file when the source throws', function (): void {
    $fixture = ordersExportFixture();

    $failingSource = new class implements ExportSource
    {
        public function type(): ExportType
        {
            return ExportType::Orders;
        }

        public function rules(): array
        {
            return [];
        }

        public function pages(string $tenantId, array $parameters): iterable
        {
            throw new RuntimeException('simulated source failure');
        }

        public function columns(): array
        {
            return ['id' => fn (object $row): string => ''];
        }
    };

    app(ExportSourceRegistry::class)->register($failingSource);

    $exportId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => Export::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'type' => ExportType::Orders,
        'requested_by_user_id' => $fixture['userId'],
        'parameters' => ['event_id' => $fixture['eventId']],
    ])->id);

    BuildExportJob::dispatch($exportId);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($exportId): void {
        $export = Export::query()->findOrFail($exportId);

        expect($export->status)->toBe(ExportStatus::Failed)
            ->and($export->failure_code)->toBe('export_source_failed')
            ->and($export->row_count)->toBeNull()
            ->and($export->completed_at)->toBeNull()
            ->and($export->getMedia('export_file'))->toBeEmpty();
    });
});
