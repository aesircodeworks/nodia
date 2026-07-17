<?php

use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Actions\AnonymizeCustomer;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Jobs\BuildDataSubjectExportJob;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\Ticket;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 2 Feature (end to end): "after the queued job
 * runs, the request is completed with a download_url; the downloaded
 * document contains the customer profile and the customer's orders,
 * tickets, payments, refunds, and check-ins for that tenant only, all
 * money as {amount, currency}, snake_case throughout" and "exporting an
 * anonymized customer succeeds and contains placeholders, not recovered
 * PII" and "running the export job twice produces one attachment and
 * one completed transition" (task breakdown item 6). No Queue::fake()
 * here, mirroring tests/Feature/Reporting/OrdersExportTest.php: the job
 * is dispatched directly and runs synchronously (QUEUE_CONNECTION=sync
 * in every test environment in this codebase).
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
            foreach ([
                'media', 'data_subject_requests', 'outbox_deliveries', 'outbox_events',
                'check_ins', 'refunds', 'payments', 'tickets', 'order_items', 'orders',
                'customers', 'ticket_types', 'events',
            ] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

/**
 * One of every category the assembler covers, for one customer, plus a
 * second customer in the same tenant carrying its own order so cross-
 * customer leakage would be caught by a stray row in the document.
 *
 * @return array{tenantId: string, customerId: string, requestedByUserId: string, orderId: string, ticketId: string, paymentId: string}
 */
function dataSubjectExportFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Export Me',
            'email' => 'export-me@example.com',
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
            'subtotal' => Money::of(5000, 'USD'),
            'discount' => Money::of(0, 'USD'),
            'fees' => Money::of(200, 'USD'),
            'total' => Money::of(5200, 'USD'),
        ]);

        OrderItem::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'unit_price' => Money::of(5000, 'USD'),
        ]);

        $ticket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            'attendee_name' => 'Export Me',
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'money' => Money::of(5200, 'USD'),
        ]);

        Refund::factory()->create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'money' => Money::of(1000, 'USD'),
            'reason' => 'event_canceled',
        ]);

        CheckIn::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $event->id,
            'user_id' => $userId,
        ]);

        // A second customer's order in the same tenant: absent from the
        // document, proving the assembler scopes strictly by customer_id
        // and not merely by tenant_id.
        $otherCustomer = Customer::factory()->create(['tenant_id' => $tenantId]);
        Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $otherCustomer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        return [
            'tenantId' => $tenantId,
            'customerId' => $customer->id,
            'requestedByUserId' => $userId,
            'orderId' => $order->id,
            'ticketId' => $ticket->id,
            'paymentId' => $payment->id,
        ];
    });
}

/**
 * @return array<string, mixed>
 */
function readDataSubjectExportDocument(string $tenantId, string $requestId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($requestId): array {
        $request = DataSubjectRequest::query()->findOrFail($requestId);
        $media = $request->getMedia('data_subject_export')->first();
        $contents = Storage::disk(config()->string('media.protected_disk'))->get($media->getPathRelativeToRoot());

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    });
}

it('completes an export containing the customer profile, orders, tickets, payments, refunds, and check-ins, scoped to that customer and tenant, wire-conventional throughout', function (): void {
    $fixture = dataSubjectExportFixture();

    $requestId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => DataSubjectRequest::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'customer_id' => $fixture['customerId'],
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Pending,
        'requested_by_user_id' => $fixture['requestedByUserId'],
    ])->id);

    BuildDataSubjectExportJob::dispatch($requestId);

    $request = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DataSubjectRequest::query()->findOrFail($requestId),
    );

    expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->completed_at)->not->toBeNull();

    $media = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => $request->getMedia('data_subject_export'),
    );

    expect($media)->toHaveCount(1)
        ->and($media->first()->mime_type)->toBe('application/json');

    $document = readDataSubjectExportDocument($fixture['tenantId'], $requestId);

    expect(array_keys($document))->toBe(['customer', 'orders', 'tickets', 'payments', 'refunds', 'check_ins']);

    expect($document['customer'])->toMatchArray([
        'id' => $fixture['customerId'],
        'email' => 'export-me@example.com',
        'name' => 'Export Me',
        'anonymized_at' => null,
    ]);

    expect($document['orders'])->toHaveCount(1);
    expect($document['orders'][0])->toMatchArray([
        'id' => $fixture['orderId'],
        'status' => 'paid',
        'subtotal' => ['amount' => 5000, 'currency' => 'USD'],
        'discount' => ['amount' => 0, 'currency' => 'USD'],
        'fees' => ['amount' => 200, 'currency' => 'USD'],
        'total' => ['amount' => 5200, 'currency' => 'USD'],
    ]);

    expect($document['tickets'])->toHaveCount(1);
    expect($document['tickets'][0])->toMatchArray([
        'id' => $fixture['ticketId'],
        'order_id' => $fixture['orderId'],
        'attendee_name' => 'Export Me',
        'list_price' => ['amount' => 5000, 'currency' => 'USD'],
    ]);

    expect($document['payments'])->toHaveCount(1);
    expect($document['payments'][0])->toMatchArray([
        'id' => $fixture['paymentId'],
        'order_id' => $fixture['orderId'],
        'gateway' => 'fake',
        'method' => 'card',
        'amount' => ['amount' => 5200, 'currency' => 'USD'],
    ]);

    expect($document['refunds'])->toHaveCount(1);
    expect($document['refunds'][0])->toMatchArray([
        'payment_id' => $fixture['paymentId'],
        'amount' => ['amount' => 1000, 'currency' => 'USD'],
        'reason' => 'event_canceled',
    ]);

    expect($document['check_ins'])->toHaveCount(1);
    expect($document['check_ins'][0])->toMatchArray([
        'ticket_id' => $fixture['ticketId'],
        'result' => 'accepted',
    ]);
});

it('exports an anonymized customer with the current placeholder name and email, never the recovered originals', function (): void {
    $fixture = dataSubjectExportFixture();

    $requestId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => DataSubjectRequest::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'customer_id' => $fixture['customerId'],
        'type' => DataSubjectRequestType::Erasure,
        'status' => DataSubjectRequestStatus::Pending,
        'requested_by_user_id' => $fixture['requestedByUserId'],
    ])->id);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture, $requestId): void {
        DataSubjectRequest::claim($requestId);

        $customer = Customer::query()->findOrFail($fixture['customerId']);
        app(AnonymizeCustomer::class)($customer, $requestId);

        DataSubjectRequest::complete($requestId);
    });

    $anonymizedCustomer = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => Customer::query()->findOrFail($fixture['customerId']),
    );

    expect($anonymizedCustomer->name)->not->toBe('Export Me')
        ->and($anonymizedCustomer->email)->not->toBe('export-me@example.com')
        ->and($anonymizedCustomer->anonymized_at)->not->toBeNull();

    $exportRequestId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => DataSubjectRequest::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'customer_id' => $fixture['customerId'],
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Pending,
        'requested_by_user_id' => $fixture['requestedByUserId'],
    ])->id);

    BuildDataSubjectExportJob::dispatch($exportRequestId);

    $document = readDataSubjectExportDocument($fixture['tenantId'], $exportRequestId);

    expect($document['customer']['name'])->toBe($anonymizedCustomer->name)
        ->and($document['customer']['email'])->toBe($anonymizedCustomer->email)
        ->and($document['customer']['name'])->not->toBe('Export Me')
        ->and($document['customer']['email'])->not->toBe('export-me@example.com')
        ->and($document['customer']['anonymized_at'])->not->toBeNull();
});

it('produces exactly one attachment and one completed transition when the build job runs twice', function (): void {
    $fixture = dataSubjectExportFixture();

    $requestId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => DataSubjectRequest::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'customer_id' => $fixture['customerId'],
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Pending,
        'requested_by_user_id' => $fixture['requestedByUserId'],
    ])->id);

    BuildDataSubjectExportJob::dispatch($requestId);

    $firstCompletion = app(TenantTransaction::class)->asTenant(
        $fixture['tenantId'],
        fn () => DataSubjectRequest::query()->findOrFail($requestId)->completed_at,
    );

    BuildDataSubjectExportJob::dispatch($requestId);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($requestId, $firstCompletion): void {
        $request = DataSubjectRequest::query()->findOrFail($requestId);

        expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
            ->and($request->completed_at->equalTo($firstCompletion))->toBeTrue()
            ->and($request->getMedia('data_subject_export'))->toHaveCount(1);
    });
});

it('claims the request via the pending to processing conditional update before building', function (): void {
    $fixture = dataSubjectExportFixture();

    $requestId = app(TenantTransaction::class)->asTenant($fixture['tenantId'], fn (): string => DataSubjectRequest::factory()->create([
        'tenant_id' => $fixture['tenantId'],
        'customer_id' => $fixture['customerId'],
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Processing,
        'requested_by_user_id' => $fixture['requestedByUserId'],
    ])->id);

    // Already processing (another worker claimed it): claim() finds zero
    // matching rows and the job exits without attaching anything.
    BuildDataSubjectExportJob::dispatch($requestId);

    app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($requestId): void {
        $request = DataSubjectRequest::query()->findOrFail($requestId);

        expect($request->status)->toBe(DataSubjectRequestStatus::Processing)
            ->and($request->getMedia('data_subject_export'))->toBeEmpty();
    });
});

it('exits cleanly for a request id that resolves to no row, rather than throwing', function (): void {
    $requestId = Str::uuid7()->toString();

    // Nothing in this stage deletes a data_subject_requests row between
    // dispatch and this job running; guarded anyway, the same defensive
    // posture App\Reporting\Jobs\BuildExportJob takes on the identical
    // vanished-row case. Reaching this assertion means dispatch() above
    // did not throw: the job resolved no tenant for this id and
    // returned before opening any tenant transaction.
    BuildDataSubjectExportJob::dispatch($requestId);

    expect(true)->toBeTrue();
});
