<?php

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
use App\Models\User;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Reporting\Jobs\ProjectEventAttendance;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 5 Feature tests: TicketCheckedIn and
 * DuplicateScanDetected delivered through the real check-in pipeline
 * (QUEUE_CONNECTION=sync stands in for Redis locally, the same posture
 * every other outbox feature test in this codebase already runs
 * under), plus the mandated duplicate-delivery test for each event type
 * and the out-of-order-delivery LEAST/GREATEST bound test the plan
 * calls out by name.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-13T13:00:00Z'));
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach ([
                'report_event_attendance', 'outbox_deliveries', 'outbox_events',
                'check_ins', 'event_signing_keys', 'memberships', 'roles',
                'tickets', 'order_items', 'orders', 'customers', 'ticket_types', 'events',
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
 * One tenant, one published event, one ticket type, one signing key,
 * and one staff user holding checkin.manage (so RecordScan's own
 * authorization check passes without a per-event CheckInAssignment
 * row, mirroring tests/Concurrency/CheckInScanContentionTest.php's own
 * fixture).
 *
 * @return array{tenantId: string, eventId: string, ticketTypeId: string, userId: string, secret: string}
 */
function attendanceFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $state = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        $secret = 'attendance-secret-'.Str::uuid7();
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

    return array_merge(['tenantId' => $tenantId], $state);
}

function attendanceTicket(string $tenantId, string $eventId, string $ticketTypeId): string
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

function attendancePayload(string $ticketId, string $eventId, string $secret): string
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
 * Records one scan through the real RecordScan action, inside a tenant
 * transaction, and returns the resulting check_in's result ('accepted'
 * or 'duplicate'). Under the sync queue, ProjectEventAttendance already
 * runs once for the resulting event before this function returns,
 * unless Queue::fake() is active.
 */
function attendanceScan(string $tenantId, string $ticketId, string $eventId, string $userId, string $secret, string $deviceId, CarbonImmutable $scannedAt): string
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($ticketId, $eventId, $userId, $secret, $deviceId, $scannedAt): string {
        $data = new RecordScanData(
            attendancePayload($ticketId, $eventId, $secret),
            $deviceId,
            (string) Str::uuid7(),
            $scannedAt->toIso8601String(),
        );

        return (app(RecordScan::class))($data, $userId)->data->result->value;
    });
}

function attendanceOutboxEventId(string $tenantId, string $type, string $ticketId): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', $type)->where('aggregate_id', $ticketId)->firstOrFail()->id,
    );
}

function processAttendanceDelivery(string $eventId): void
{
    app(ProcessOutboxDelivery::class, ['eventId' => $eventId, 'subscriber' => ProjectEventAttendance::NAME])->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
}

/**
 * @return object|null
 */
function attendanceRow(string $tenantId, string $eventId, string $ticketTypeId)
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('report_event_attendance')->where('event_id', $eventId)->where('ticket_type_id', $ticketTypeId)->first(),
    );
}

it('increments checked_in_count and sets both scan-timestamp bounds when a TicketCheckedIn is delivered', function (): void {
    $fx = attendanceFixture();
    $ticketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);
    $scannedAt = CarbonImmutable::parse('2026-07-13T12:00:00Z');

    $result = attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-1', $scannedAt);

    expect($result)->toBe('accepted');

    $row = attendanceRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->checked_in_count)->toBe(1)
        ->and((int) $row->duplicate_scan_count)->toBe(0)
        ->and(CarbonImmutable::parse($row->first_scan_at)->utc()->toIso8601String())->toBe($scannedAt->toIso8601String())
        ->and(CarbonImmutable::parse($row->last_scan_at)->utc()->toIso8601String())->toBe($scannedAt->toIso8601String());
});

it('increments only duplicate_scan_count when a DuplicateScanDetected is delivered, leaving the first scan bounds untouched', function (): void {
    $fx = attendanceFixture();
    $ticketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);
    $firstScannedAt = CarbonImmutable::parse('2026-07-13T12:00:00Z');
    $secondScannedAt = CarbonImmutable::parse('2026-07-13T12:10:00Z');

    $first = attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-1', $firstScannedAt);
    $second = attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-2', $secondScannedAt);

    expect($first)->toBe('accepted')
        ->and($second)->toBe('duplicate');

    $row = attendanceRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->checked_in_count)->toBe(1)
        ->and((int) $row->duplicate_scan_count)->toBe(1)
        ->and(CarbonImmutable::parse($row->first_scan_at)->utc()->toIso8601String())->toBe($firstScannedAt->toIso8601String())
        ->and(CarbonImmutable::parse($row->last_scan_at)->utc()->toIso8601String())->toBe($firstScannedAt->toIso8601String());
});

it('leaves first_scan_at at the earlier and last_scan_at at the later instant regardless of delivery order', function (): void {
    Queue::fake();

    $fx = attendanceFixture();
    $laterTicketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);
    $earlierTicketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    $laterScannedAt = CarbonImmutable::parse('2026-07-13T12:05:00Z');
    $earlierScannedAt = CarbonImmutable::parse('2026-07-13T12:00:00Z');

    attendanceScan($fx['tenantId'], $laterTicketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-1', $laterScannedAt);
    attendanceScan($fx['tenantId'], $earlierTicketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-2', $earlierScannedAt);

    $laterEventId = attendanceOutboxEventId($fx['tenantId'], 'TicketCheckedIn', $laterTicketId);
    $earlierEventId = attendanceOutboxEventId($fx['tenantId'], 'TicketCheckedIn', $earlierTicketId);

    // Deliver the later scan's event first, the earlier scan's second:
    // the opposite of scan order, proving the bounds are order-
    // independent, not merely first-write-wins.
    processAttendanceDelivery($laterEventId);
    processAttendanceDelivery($earlierEventId);

    $row = attendanceRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->checked_in_count)->toBe(2)
        ->and(CarbonImmutable::parse($row->first_scan_at)->utc()->toIso8601String())->toBe($earlierScannedAt->toIso8601String())
        ->and(CarbonImmutable::parse($row->last_scan_at)->utc()->toIso8601String())->toBe($laterScannedAt->toIso8601String());
});

it('causes exactly one increment when a TicketCheckedIn delivery is repeated', function (): void {
    $fx = attendanceFixture();
    $ticketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);
    $scannedAt = CarbonImmutable::parse('2026-07-13T12:00:00Z');

    attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-1', $scannedAt);
    $eventId = attendanceOutboxEventId($fx['tenantId'], 'TicketCheckedIn', $ticketId);

    processOutboxDeliveryTwice($eventId, ProjectEventAttendance::NAME);

    $row = attendanceRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->checked_in_count)->toBe(1);
});

it('causes exactly one increment when a DuplicateScanDetected delivery is repeated', function (): void {
    $fx = attendanceFixture();
    $ticketId = attendanceTicket($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-1', CarbonImmutable::parse('2026-07-13T12:00:00Z'));
    attendanceScan($fx['tenantId'], $ticketId, $fx['eventId'], $fx['userId'], $fx['secret'], 'device-2', CarbonImmutable::parse('2026-07-13T12:10:00Z'));
    $eventId = attendanceOutboxEventId($fx['tenantId'], 'DuplicateScanDetected', $ticketId);

    processOutboxDeliveryTwice($eventId, ProjectEventAttendance::NAME);

    $row = attendanceRow($fx['tenantId'], $fx['eventId'], $fx['ticketTypeId']);

    expect((int) $row->duplicate_scan_count)->toBe(1);
});
