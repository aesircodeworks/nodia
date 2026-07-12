<?php

use App\CheckIn\Models\CheckInAssignment;
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
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-09 plan, Endpoints "POST /v1/check-in-batches" and Slice 5: the
 * cross-device duplicate matrix in both submission orders plus the
 * equal-timestamp tie-break, partial outcomes, resubmission idempotence,
 * rejected-then-cleared re-verification, batch_too_large, and
 * unassigned-event-rejected-in-batch, all against the contract.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('check_ins')->where('tenant_id', $this->tenantId)->delete();
        DB::table('check_in_assignments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_signing_keys')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('roles')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );

    User::query()->delete();
});

/**
 * @return array{eventId: string, ticketId: string}
 */
function batchTicket(string $tenantId, array $overrides = []): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $overrides): array {
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
            ...$overrides,
        ]);

        return ['eventId' => $event->id, 'ticketId' => $ticket->id];
    });
}

function batchKey(string $tenantId, string $eventId, string $secret): EventSigningKey
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'key_version' => 1,
            'status' => SigningKeyStatus::Active,
            'secret' => $secret,
        ]),
    );
}

function batchPayload(string $ticketId, string $eventId, int $rotation, string $secret): string
{
    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|'.$rotation, $secret);

    $body = json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => $rotation,
        'signature' => $signature,
    ]);

    return rtrim(strtr(base64_encode((string) $body), '+/', '-_'), '=');
}

function batchManageHeaders(string $tenantId): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $tenantId,
    ];
}

/**
 * @return array{userId: string, headers: array<string, string>}
 */
function batchAssignedScanner(string $tenantId, string $eventId): array
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);
    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $eventId): void {
        $role = Role::factory()->create([
            'tenant_id' => $tenantId,
            'capabilities' => [Capability::CheckinScan->value],
        ]);
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
            'scope' => MembershipScope::Tenant,
        ]);
        CheckInAssignment::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'user_id' => $user->id,
        ]);
    });

    return [
        'userId' => $user->id,
        'headers' => [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenantId,
        ],
    ];
}

it('resolves same-ticket duplicates first-scan-wins when the earlier scan arrives first', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = batchManageHeaders($this->tenantId);

    $earlier = now()->subMinutes(10)->toIso8601String();
    $later = now()->subMinutes(5)->toIso8601String();

    $first = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => $earlier]],
    ], $headers);
    $first->assertStatus(200)->assertConformsToOpenApi();
    $first->assertJsonPath('results.0.outcome', 'accepted');

    $second = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-2',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => $later]],
    ], $headers);
    $second->assertStatus(200)->assertConformsToOpenApi();
    $second->assertJsonPath('results.0.outcome', 'duplicate');
    $second->assertJsonPath('results.0.code', 'ticket_already_checked_in');

    [$accepted, $duplicates, $checkedInEvents, $duplicateEvents] = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => [
        DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'accepted')->count(),
        DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'duplicate')->count(),
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'TicketCheckedIn')->count(),
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'DuplicateScanDetected')->count(),
    ]);
    expect($accepted)->toBe(1)->and($duplicates)->toBe(1)
        ->and($checkedInEvents)->toBe(1)->and($duplicateEvents)->toBe(1);
});

it('swaps the accepted scan when the earlier-timestamped scan arrives second, without re-recording TicketCheckedIn', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = batchManageHeaders($this->tenantId);

    $earlier = now()->subMinutes(10)->toIso8601String();
    $later = now()->subMinutes(5)->toIso8601String();

    // Later-arriving scan submitted first, becomes accepted.
    $first = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-2',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => $later]],
    ], $headers);
    $first->assertStatus(200);
    $first->assertJsonPath('results.0.outcome', 'accepted');
    $firstCheckInId = $first->json('results.0.check_in_id');

    // Earlier-timestamped scan arrives second: swaps in as accepted.
    $second = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => $earlier]],
    ], $headers);
    $second->assertStatus(200)->assertConformsToOpenApi();
    $second->assertJsonPath('results.0.outcome', 'accepted');

    [$acceptedId, $demotedResult, $checkedInEvents, $duplicateEvents] = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => [
        DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'accepted')->value('id'),
        DB::table('check_ins')->where('id', $firstCheckInId)->value('result'),
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'TicketCheckedIn')->count(),
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'DuplicateScanDetected')->count(),
    ]);

    expect($acceptedId)->toBe($second->json('results.0.check_in_id'))
        ->and($demotedResult)->toBe('duplicate')
        ->and($checkedInEvents)->toBe(1)
        ->and($duplicateEvents)->toBe(1);
});

it('breaks equal-timestamp ties by smallest client_scan_id regardless of submission order', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = batchManageHeaders($this->tenantId);

    $scannedAt = now()->subMinutes(5)->toIso8601String();
    $smaller = '00000000-0000-7000-8000-000000000001';
    $larger = 'ffffffff-ffff-7fff-bfff-ffffffffffff';

    $first = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-larger',
        'scans' => [['client_scan_id' => $larger, 'qr_payload' => $payload, 'scanned_at' => $scannedAt]],
    ], $headers);
    $first->assertStatus(200);
    $first->assertJsonPath('results.0.outcome', 'accepted');

    $second = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-smaller',
        'scans' => [['client_scan_id' => $smaller, 'qr_payload' => $payload, 'scanned_at' => $scannedAt]],
    ], $headers);
    $second->assertStatus(200);
    $second->assertJsonPath('results.0.outcome', 'accepted');

    $acceptedDevice = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->where('result', 'accepted')->value('device_id'),
    );
    expect($acceptedDevice)->toBe('device-smaller');
});

it('reports partial outcomes in one batch without failing it', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $goodPayload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $badPayload = batchPayload($ticketId, $eventId, 0, 'wrong-secret');
    $headers = batchManageHeaders($this->tenantId);

    $response = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [
            ['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $goodPayload, 'scanned_at' => now()->subMinutes(10)->toIso8601String()],
            ['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $goodPayload, 'scanned_at' => now()->subMinutes(5)->toIso8601String()],
            ['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $badPayload, 'scanned_at' => now()->subMinutes(5)->toIso8601String()],
        ],
    ], $headers);

    $response->assertStatus(200)->assertConformsToOpenApi();
    $response->assertJsonPath('results.0.outcome', 'accepted');
    $response->assertJsonPath('results.1.outcome', 'duplicate');
    $response->assertJsonPath('results.2.outcome', 'rejected');
    $response->assertJsonPath('results.2.code', 'qr_signature_invalid');
});

it('resubmits a full batch idempotently: recorded outcomes replay, nothing new is written', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = batchManageHeaders($this->tenantId);
    $clientScanId = (string) Str::uuid7();

    $body = [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => $clientScanId, 'qr_payload' => $payload, 'scanned_at' => now()->subMinutes(5)->toIso8601String()]],
    ];

    $first = $this->postJson('/v1/check-in-batches', $body, $headers);
    $first->assertStatus(200);
    $firstCheckInId = $first->json('results.0.check_in_id');

    $second = $this->postJson('/v1/check-in-batches', $body, $headers);
    $second->assertStatus(200)->assertConformsToOpenApi();
    $second->assertJsonPath('results.0.outcome', 'accepted');
    $second->assertJsonPath('results.0.check_in_id', $firstCheckInId);

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
    );
    expect($rows)->toBe(1);
});

it('re-verifies a rejected scan from scratch on resubmission once the blocking condition clears', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    ['eventId' => $otherEventId] = batchTicket($this->tenantId);
    ['userId' => $userId, 'headers' => $headers] = batchAssignedScanner($this->tenantId, $otherEventId);
    $clientScanId = (string) Str::uuid7();

    $body = [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => $clientScanId, 'qr_payload' => $payload, 'scanned_at' => now()->subMinutes(5)->toIso8601String()]],
    ];

    $first = $this->postJson('/v1/check-in-batches', $body, $headers);
    $first->assertStatus(200);
    $first->assertJsonPath('results.0.outcome', 'rejected');
    $first->assertJsonPath('results.0.code', 'checkin_not_assigned');

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($eventId, $userId): void {
        CheckInAssignment::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $eventId,
            'user_id' => $userId,
        ]);
    });

    $second = $this->postJson('/v1/check-in-batches', $body, $headers);
    $second->assertStatus(200)->assertConformsToOpenApi();
    $second->assertJsonPath('results.0.outcome', 'accepted');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
    );
    expect($rows)->toBe(1);
});

it('rejects a caller holding no checkin capability wholesale with 403, never a 200 batch of rejected outcomes', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => now()->toIso8601String()]],
    ], $headers);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'checkin_not_assigned');
});

it('rejects a non-uuid client_scan_id with a validation problem', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => 'not-a-uuid', 'qr_payload' => $payload, 'scanned_at' => now()->toIso8601String()]],
    ], batchManageHeaders($this->tenantId));

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'request.validation_failed');
});

it('rejects a batch of more than 500 scans wholesale with batch_too_large', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = batchManageHeaders($this->tenantId);

    $scans = [];
    for ($i = 0; $i < 501; $i++) {
        $scans[] = ['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => now()->toIso8601String()];
    }

    $response = $this->postJson('/v1/check-in-batches', ['device_id' => 'device-1', 'scans' => $scans], $headers);

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'batch_too_large');
});

it('rejects scans for an unassigned event in the batch without failing it', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = batchTicket($this->tenantId);
    batchKey($this->tenantId, $eventId, 'secret-1');
    $payload = batchPayload($ticketId, $eventId, 0, 'secret-1');
    ['eventId' => $otherEventId] = batchTicket($this->tenantId);
    ['headers' => $headers] = batchAssignedScanner($this->tenantId, $otherEventId);

    $response = $this->postJson('/v1/check-in-batches', [
        'device_id' => 'device-1',
        'scans' => [['client_scan_id' => (string) Str::uuid7(), 'qr_payload' => $payload, 'scanned_at' => now()->toIso8601String()]],
    ], $headers);

    $response->assertStatus(200)->assertConformsToOpenApi();
    $response->assertJsonPath('results.0.outcome', 'rejected');
    $response->assertJsonPath('results.0.code', 'checkin_not_assigned');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
    );
    expect($rows)->toBe(0);
});
