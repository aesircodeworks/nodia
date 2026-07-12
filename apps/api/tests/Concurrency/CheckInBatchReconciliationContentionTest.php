<?php

use App\CheckIn\Actions\ReconcileOfflineScans;
use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\OfflineScanData;
use App\CheckIn\Data\ReconcileBatchData;
use App\CheckIn\Data\RecordScanData;
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
 * Stage-09 plan, Slice 5 concurrency rules: overlapping offline batches
 * from distinct devices resolve to exactly one accepted row per
 * contested ticket, holding the earliest scanned_at, and exactly one
 * TicketCheckedIn; a batch swap racing an online scan for the same
 * ticket resolves to the same invariant.
 */
const BATCH_WORKERS = 6;

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

/**
 * @return array{eventId: string, ticketId: string, userId: string, secret: string}
 */
function batchContentionFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant(
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
}

function batchContentionPayload(string $ticketId, string $eventId, string $secret): string
{
    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|0', $secret);
    $body = json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => 0,
        'signature' => $signature,
    ]);

    return rtrim(strtr(base64_encode((string) $body), '+/', '-_'), '=');
}

it('resolves overlapping parallel batches to exactly one earliest-timestamp accepted row and one TicketCheckedIn', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['eventId' => $eventId, 'ticketId' => $ticketId, 'userId' => $userId, 'secret' => $secret] = batchContentionFixture($tenantId);
    $payload = batchContentionPayload($ticketId, $eventId, $secret);

    $base = now()->subHour();

    $task = function (int $worker) use ($tenantId, $userId, $payload, $base): callable {
        return function () use ($tenantId, $userId, $payload, $base, $worker): string {
            return app(TenantTransaction::class)->asTenant($tenantId, function () use ($worker, $userId, $payload, $base): string {
                $scannedAt = $base->clone()->addSeconds($worker)->toIso8601String();

                $data = new ReconcileBatchData('device-'.$worker, [
                    new OfflineScanData((string) Str::uuid7(), $payload, $scannedAt),
                ]);

                $result = (app(ReconcileOfflineScans::class))($data, $userId);

                return $result->results[0]->outcome->value;
            });
        };
    };

    ParallelRunner::runEach(...array_map($task, range(0, BATCH_WORKERS - 1)));

    [$acceptedRows, $checkedInEvents] = app(TenantTransaction::class)->asTenant($tenantId, fn () => [
        CheckIn::query()->where('ticket_id', $ticketId)->where('result', 'accepted')->get(['device_id']),
        OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'TicketCheckedIn')->count(),
    ]);

    $totalRows = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => CheckIn::query()->where('ticket_id', $ticketId)->count(),
    );

    expect($acceptedRows)->toHaveCount(1)
        ->and($acceptedRows->first()->device_id)->toBe('device-0')
        ->and($checkedInEvents)->toBe(1)
        ->and($totalRows)->toBe(BATCH_WORKERS);
});

it('resolves a batch swap racing an online scan to the same first-scan-wins invariant', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    ['eventId' => $eventId, 'ticketId' => $ticketId, 'userId' => $userId, 'secret' => $secret] = batchContentionFixture($tenantId);
    $payload = batchContentionPayload($ticketId, $eventId, $secret);

    $earlier = now()->subHour();
    $later = now()->subMinutes(30);

    $batchWorker = function () use ($tenantId, $userId, $payload, $earlier): string {
        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($userId, $payload, $earlier): string {
            $data = new ReconcileBatchData('batch-device', [
                new OfflineScanData((string) Str::uuid7(), $payload, $earlier->toIso8601String()),
            ]);

            $result = (app(ReconcileOfflineScans::class))($data, $userId);

            return $result->results[0]->outcome->value;
        });
    };

    $onlineWorker = function () use ($tenantId, $userId, $payload, $later): string {
        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($userId, $payload, $later): string {
            $data = new RecordScanData($payload, 'online-device', (string) Str::uuid7(), $later->toIso8601String());

            $outcome = (app(RecordScan::class))($data, $userId);

            return $outcome->data->result->value;
        });
    };

    $results = ParallelRunner::runEach($batchWorker, $onlineWorker);

    expect($results)->toHaveCount(2);

    [$acceptedRows, $checkedInEvents] = app(TenantTransaction::class)->asTenant($tenantId, fn () => [
        CheckIn::query()->where('ticket_id', $ticketId)->where('result', 'accepted')->count(),
        OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'TicketCheckedIn')->count(),
    ]);

    expect($acceptedRows)->toBe(1)->and($checkedInEvents)->toBe(1);
});
