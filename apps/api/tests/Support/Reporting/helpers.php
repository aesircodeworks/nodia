<?php

declare(strict_types=1);

use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\RecordScanData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Models\User;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Actions\MarkTicketsRefunded;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Actions\CreateRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixture and processing helpers for stage-11 plan task 13
 * (reporting:rebuild) coverage, spanning all three projections. Reuses
 * the same real-pipeline shapes tests/Feature/Reporting/
 * DailySalesProjectionTest.php, EventFinanceProjectionTest.php, and
 * EventAttendanceProjectionTest.php already established, prefixed
 * distinctly (reportingRebuild*) so their top-level function names never
 * collide when Pest loads every test file in one process.
 */
function reportingRebuildTenant(array $overrides = []): string
{
    return app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create($overrides)->id);
}

/**
 * @return array{eventId: string, ticketTypeId: string}
 */
function reportingRebuildSalesFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 50,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['eventId' => $event->id, 'ticketTypeId' => $ticketType->id];
    });
}

/**
 * One paid order of one ticket, issued through the real hold-to-paid
 * pipeline. Caller wraps in Queue::fake() to control delivery timing.
 *
 * @return array{orderId: string, ticketId: string}
 */
function reportingRebuildIssueTicket(string $tenantId, string $eventId, string $ticketTypeId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $ticketTypeId): array {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $eventId,
                'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        app(MarkOrderAwaitingPayment::class)($orderId);
        app(MarkOrderPaid::class)($orderId);

        $ticketId = DB::table('tickets')->where('order_id', $orderId)->value('id');

        return ['orderId' => $orderId, 'ticketId' => $ticketId];
    });
}

function reportingRebuildRefundTicket(string $tenantId, string $orderId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($orderId): void {
        DB::transaction(fn () => app(MarkTicketsRefunded::class)($orderId, null, (string) Str::uuid7()));
    });
}

function reportingRebuildFinanceEvent(string $tenantId): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId])->id,
    );
}

/**
 * @return array{paymentId: string, orderId: string}
 */
function reportingRebuildConfirmPayment(string $tenantId, string $eventId, int $amount, int $fee): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $amount, $fee): array {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $eventId,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Initiated,
            'money' => Money::of($amount, 'USD'),
        ]);

        app(ConfirmPayment::class)($payment->id, Money::of($fee, 'USD'));

        return ['paymentId' => $payment->id, 'orderId' => $order->id];
    });
}

/**
 * A confirmed payment plus a full refund delivered end to end through
 * FakeGateway's webhook (mirrors tests/Feature/Payments/
 * RefundCompletionTest.php's completionFixture).
 *
 * @return array{refundId: string, paymentId: string}
 */
function reportingRebuildCompleteRefund(string $tenantId, string $eventId, int $amount, int $fee): array
{
    $payment = reportingRebuildConfirmPayment($tenantId, $eventId, $amount, $fee);

    $refundId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($payment): string {
        DB::table('payments')->where('id', $payment['paymentId'])->update(['gateway_reference' => 'fake_'.Str::uuid7()]);

        return app(CreateRefund::class)(
            $payment['paymentId'],
            CreateRefundData::from(['amount' => null, 'ticket_ids' => null]),
            (string) Str::uuid7(),
        )->refund->id;
    });

    $delivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refundId);

    test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body)->assertStatus(200);

    return ['refundId' => $refundId, 'paymentId' => $payment['paymentId']];
}

/**
 * @return array{eventId: string, ticketTypeId: string, userId: string, secret: string}
 */
function reportingRebuildAttendanceFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        $secret = 'reporting-rebuild-secret-'.Str::uuid7();
        EventSigningKey::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'key_version' => 1,
            'status' => SigningKeyStatus::Active,
            'secret' => $secret,
        ]);

        $user = User::factory()->create();
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create([
                'tenant_id' => $tenantId,
                'capabilities' => [Capability::CheckinManage->value],
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);

        return ['eventId' => $event->id, 'ticketTypeId' => $ticketType->id, 'userId' => $user->id, 'secret' => $secret];
    });
}

function reportingRebuildAttendanceTicket(string $tenantId, string $eventId, string $ticketTypeId): string
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $ticketTypeId): string {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $eventId,
            'hold_id' => Str::uuid7()->toString(),
        ]);
        $ticket = Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketTypeId,
            'event_id' => $eventId,
        ]);

        return $ticket->id;
    });
}

function reportingRebuildScanPayload(string $ticketId, string $eventId, string $secret): string
{
    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|0', $secret);

    return rtrim(strtr(base64_encode((string) json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => 0,
        'signature' => $signature,
    ])), '+/', '-_'), '=');
}

/**
 * Records one scan through the real RecordScan action; returns 'accepted'
 * or 'duplicate'.
 */
function reportingRebuildScan(string $tenantId, string $ticketId, string $eventId, string $userId, string $secret, string $deviceId, CarbonImmutable $scannedAt): string
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($ticketId, $eventId, $userId, $secret, $deviceId, $scannedAt): string {
        $data = new RecordScanData(
            reportingRebuildScanPayload($ticketId, $eventId, $secret),
            $deviceId,
            (string) Str::uuid7(),
            $scannedAt->toIso8601String(),
        );

        return (app(RecordScan::class))($data, $userId)->data->result->value;
    });
}

/**
 * Every pending delivery for the given tenant, across every subscriber,
 * in an unspecified (insertion) order: callers shuffle before processing
 * to prove the projectors converge regardless of delivery order.
 *
 * @return list<array{eventId: string, subscriber: string}>
 */
function reportingRebuildPendingDeliveries(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn (): array => OutboxDelivery::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OutboxDeliveryStatus::Pending)
            ->get(['outbox_event_id', 'subscriber'])
            ->map(fn ($row): array => ['eventId' => $row->outbox_event_id, 'subscriber' => $row->subscriber])
            ->all(),
    );
}

/**
 * Processes one delivery directly through ProcessOutboxDelivery::handle(),
 * bypassing the queue so the caller fully controls delivery order.
 */
function reportingRebuildProcessDelivery(string $eventId, string $subscriber): void
{
    app(ProcessOutboxDelivery::class, ['eventId' => $eventId, 'subscriber' => $subscriber])->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

/**
 * @return Collection<int, object>
 */
function reportingRebuildDailySalesRows(string $tenantId): Collection
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn (): Collection => DB::table('report_daily_sales')
            ->where('tenant_id', $tenantId)
            ->orderBy('event_id')->orderBy('ticket_type_id')->orderBy('sales_date')
            ->get()
            ->map(fn (object $row): array => collect((array) $row)->except(['id', 'created_at', 'updated_at'])->all())
            ->values(),
    );
}

/**
 * @return Collection<int, object>
 */
function reportingRebuildEventFinanceRows(string $tenantId): Collection
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn (): Collection => DB::table('report_event_finance')
            ->where('tenant_id', $tenantId)
            ->orderBy('event_id')
            ->get()
            ->map(fn (object $row): array => collect((array) $row)->except(['id', 'created_at', 'updated_at'])->all())
            ->values(),
    );
}

/**
 * @return Collection<int, object>
 */
function reportingRebuildEventAttendanceRows(string $tenantId): Collection
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn (): Collection => DB::table('report_event_attendance')
            ->where('tenant_id', $tenantId)
            ->orderBy('event_id')->orderBy('ticket_type_id')
            ->get()
            ->map(fn (object $row): array => collect((array) $row)->except(['id', 'created_at', 'updated_at'])->all())
            ->values(),
    );
}

/**
 * A second, independent database connection (its own Postgres backend),
 * so a test can hold a session-scoped advisory lock open on one
 * connection while exercising ProcessOutboxDelivery on the app's own
 * connection, simulating a reporting:rebuild pass already in flight
 * without needing real OS-level parallelism (mirrors
 * tests/Concurrency/Support/ParallelRunner's own connection discipline,
 * at single-connection scale).
 */
function reportingRebuildRawConnection(): PDO
{
    $config = config()->array('database.connections.'.config()->string('database.default'));

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function reportingRebuildHoldExclusiveLock(PDO $pdo, string $projection): void
{
    $statement = $pdo->prepare('select pg_advisory_lock(hashtext(?), hashtext(?))');
    $statement->execute(['reporting_projection_rebuild', $projection]);
}

function reportingRebuildReleaseExclusiveLock(PDO $pdo, string $projection): void
{
    $statement = $pdo->prepare('select pg_advisory_unlock(hashtext(?), hashtext(?))');
    $statement->execute(['reporting_projection_rebuild', $projection]);
}

function reportingRebuildCleanTenant(string $tenantId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        DB::table('gateway_webhook_events')->where('tenant_id', $tenantId)->delete();

        foreach ([
            'report_daily_sales', 'report_event_finance', 'report_event_attendance',
            'outbox_deliveries', 'outbox_events', 'media',
            'check_ins', 'event_signing_keys', 'memberships', 'roles',
            'refunds', 'payments', 'tickets', 'order_items', 'orders', 'hold_items', 'holds',
            'customers', 'ticket_type_inventory', 'ticket_types', 'events',
        ] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    });
}

/**
 * Global teardown after every per-tenant reportingRebuildCleanTenant()
 * call: staff users and every non-platform tenant.
 */
function reportingRebuildCleanGlobal(): void
{
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
}
