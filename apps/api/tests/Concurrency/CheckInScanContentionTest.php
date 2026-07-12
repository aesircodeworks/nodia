<?php

use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\RecordScanData;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Exceptions\TicketAlreadyCheckedInException;
use App\CheckIn\Models\CheckIn;
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
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-09 plan, Slice 4 concurrency rule (written before the endpoint
 * exists, master plan test-first rule): N parallel online scans of the
 * same ticket from distinct devices produce exactly one accepted row,
 * N-1 duplicate rows, exactly one TicketCheckedIn, and N-1
 * DuplicateScanDetected. The accepted-ticket partial unique index is the
 * structural guard; RecordScan's own catch-and-fall-back-to-duplicate
 * path is what every loser exercises.
 */
const SCAN_WORKERS = 6;

beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('check_ins')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('event_signing_keys')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
        User::query()->delete();
    });
});

it('accepts exactly one scan and persists every other as a flagged duplicate under parallel contention', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['eventId' => $eventId, 'ticketId' => $ticketId, 'userId' => $userId, 'secret' => $secret] = app(TenantTransaction::class)->asTenant(
        $tenantId,
        function () use ($tenantId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
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
            $secret = 'contention-secret';
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

            return ['eventId' => $event->id, 'ticketId' => $ticket->id, 'userId' => $user->id, 'secret' => $secret];
        },
    );

    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|0', $secret);
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => 0,
        'signature' => $signature,
    ])), '+/', '-_'), '=');

    $results = ParallelRunner::run(SCAN_WORKERS, function () use ($tenantId, $ticketId, $userId, $payload): string {
        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($ticketId, $userId, $payload): string {
            $data = new RecordScanData(
                $payload,
                'device-'.$ticketId.'-'.random_int(1, 1_000_000),
                (string) Str::uuid7(),
                now()->toIso8601String(),
            );

            try {
                $outcome = (app(RecordScan::class))($data, $userId);

                return $outcome->data->result->value;
            } catch (TicketAlreadyCheckedInException) {
                return 'duplicate';
            }
        });
    });

    [$acceptedCount, $duplicateCount, $checkedInEvents, $duplicateEvents] = app(TenantTransaction::class)->asTenant($tenantId, fn () => [
        CheckIn::query()->where('ticket_id', $ticketId)->where('result', CheckInResult::Accepted)->count(),
        CheckIn::query()->where('ticket_id', $ticketId)->where('result', CheckInResult::Duplicate)->count(),
        OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'TicketCheckedIn')->count(),
        OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'DuplicateScanDetected')->count(),
    ]);

    expect($results)->toHaveCount(SCAN_WORKERS)
        ->and(array_count_values($results)['accepted'] ?? 0)->toBe(1)
        ->and(array_count_values($results)['duplicate'] ?? 0)->toBe(SCAN_WORKERS - 1)
        ->and($acceptedCount)->toBe(1)
        ->and($duplicateCount)->toBe(SCAN_WORKERS - 1)
        ->and($checkedInEvents)->toBe(1)
        ->and($duplicateEvents)->toBe(SCAN_WORKERS - 1);
});
