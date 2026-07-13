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
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 5 Concurrency test: N parallel workers projecting
 * N distinct TicketCheckedIn events for the same (event, ticket_type)
 * cell converge to exactly N increments and keep the first_scan_at and
 * last_scan_at bounds correct; the upsert-with-increments write path
 * (never read-then-write, master plan test-first rule 2) loses no
 * updates under real contention on the single contended row.
 */

const ATTENDANCE_WORKERS = 8;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
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
 * ATTENDANCE_WORKERS distinct tickets of one event and ticket type, each
 * accepted-scanned at a distinct instant through the real RecordScan
 * action (mirroring tests/Concurrency/CheckInScanContentionTest.php's
 * own fixture). Queue::fake() keeps every resulting TicketCheckedIn
 * delivery pending so the parallel workers below are the first and only
 * processors, the same discipline
 * tests/Concurrency/DailySalesProjectionContentionTest.php uses.
 *
 * @return array{tenantId: string, eventId: string, ticketTypeId: string, eventIds: list<string>, earliest: string, latest: string}
 */
function attendanceContentionFixture(): array
{
    Queue::fake();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $state = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        $secret = 'contention-secret-'.Str::uuid7();
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

        $base = CarbonImmutable::parse('2026-07-13T12:00:00Z');
        $eventIds = [];

        for ($i = 0; $i < ATTENDANCE_WORKERS; $i++) {
            $customer = Customer::factory()->create([
                'tenant_id' => $tenantId,
                'email' => Str::uuid7()->toString().'@example.com',
            ]);
            $order = Order::factory()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'hold_id' => Str::uuid7()->toString(),
            ]);
            $ticket = Ticket::factory()->create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'ticket_type_id' => $ticketType->id,
                'event_id' => $event->id,
            ]);

            $scannedAt = $base->addMinutes($i);
            $signature = hash_hmac('sha256', $ticket->id.'|'.$event->id.'|0', $secret);
            $payload = rtrim(strtr(base64_encode((string) json_encode([
                'ticket_id' => $ticket->id,
                'event_id' => $event->id,
                'rotation' => 0,
                'signature' => $signature,
            ])), '+/', '-_'), '=');

            $data = new RecordScanData($payload, 'device-'.$i, (string) Str::uuid7(), $scannedAt->toIso8601String());
            (app(RecordScan::class))($data, $user->id);

            $eventIds[] = OutboxEvent::query()
                ->where('type', 'TicketCheckedIn')
                ->where('aggregate_id', $ticket->id)
                ->firstOrFail()
                ->id;
        }

        return [
            'eventId' => $event->id,
            'ticketTypeId' => $ticketType->id,
            'eventIds' => $eventIds,
            'earliest' => $base->toIso8601String(),
            'latest' => $base->addMinutes(ATTENDANCE_WORKERS - 1)->toIso8601String(),
        ];
    });

    return array_merge(['tenantId' => $tenantId], $state);
}

it('converges to exactly N increments and keeps the scan-timestamp bounds correct when N distinct TicketCheckedIn deliveries race the same cell', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-13T13:00:00Z'));

    $fx = attendanceContentionFixture();

    expect($fx['eventIds'])->toHaveCount(ATTENDANCE_WORKERS);

    ParallelRunner::runEach(...array_map(
        fn (string $eventId): callable => function () use ($eventId): bool {
            app(ProcessOutboxDelivery::class, [
                'eventId' => $eventId,
                'subscriber' => ProjectEventAttendance::NAME,
            ])->handle(
                app(TenantTransaction::class),
                app(SubscriberRegistry::class),
                app(OrderedConsumption::class),
                app(ProjectionLock::class),
            );

            return true;
        },
        $fx['eventIds'],
    ));

    $row = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('report_event_attendance')
            ->where('event_id', $fx['eventId'])
            ->where('ticket_type_id', $fx['ticketTypeId'])
            ->first(),
    );

    expect((int) $row->checked_in_count)->toBe(ATTENDANCE_WORKERS)
        ->and((int) $row->duplicate_scan_count)->toBe(0)
        ->and(CarbonImmutable::parse($row->first_scan_at)->utc()->toIso8601String())->toBe($fx['earliest'])
        ->and(CarbonImmutable::parse($row->last_scan_at)->utc()->toIso8601String())->toBe($fx['latest']);
});
