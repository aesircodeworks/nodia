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
use App\Orders\Enums\TicketStatus;
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
 * Stage-09 plan, Endpoints "POST /v1/check-ins" and Slice 4: happy
 * path, first-scan-wins 409 with a persisted duplicate, replay
 * short-circuit, the full rejection-code matrix, and the rotated-keys
 * matrix, all against the contract.
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
function recordScanTicket(string $tenantId, array $overrides = []): array
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

function recordScanKey(string $tenantId, string $eventId, string $secret, int $version = 1, SigningKeyStatus $status = SigningKeyStatus::Active): EventSigningKey
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'key_version' => $version,
            'status' => $status,
            'secret' => $secret,
        ]),
    );
}

function recordScanPayload(string $ticketId, string $eventId, int $rotation, string $secret): string
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

function recordScanManageHeaders(string $tenantId): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, Capability::CheckinManage),
        'X-Tenant-Id' => $tenantId,
    ];
}

/**
 * @return array{userId: string, headers: array<string, string>}
 */
function recordScanAssignedScanner(string $tenantId, string $eventId): array
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

it('accepts a fresh scan and returns 201', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(201)->assertConformsToOpenApi();
    $response->assertJsonPath('ticket_id', $ticketId)
        ->assertJsonPath('event_id', $eventId)
        ->assertJsonPath('result', 'accepted');

    $events = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'TicketCheckedIn')->count(),
    );
    expect($events)->toBe(1);
});

it('rejects a second scan of the same ticket with 409 and persists the duplicate', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = recordScanManageHeaders($this->tenantId);

    $first = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);
    $first->assertStatus(201);

    $second = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-2',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $second->assertStatus(409)->assertConformsToOpenApi();
    $second->assertJsonPath('code', 'ticket_already_checked_in');
    expect($second->json('first_device_id'))->toBe('device-1');
    expect($second->json('first_scanned_at'))->not->toBeEmpty();

    [$rows, $events] = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => [
        DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'DuplicateScanDetected')->count(),
    ]);
    expect($rows)->toBe(2)
        ->and($events)->toBe(1);
});

it('audits the accepted scan and the 409 duplicate alike, and records nothing for a replay', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = recordScanManageHeaders($this->tenantId);
    $replayedScanId = (string) Str::uuid7();

    $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => $replayedScanId,
        'scanned_at' => now()->toIso8601String(),
    ], $headers)->assertStatus(201);

    // The duplicate commits a check_ins row and its outbox event behind a
    // 409, which the success-only RecordActivityAudit middleware would
    // have skipped: it is the scan most worth auditing.
    $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-2',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers)->assertStatus(409);

    $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => $replayedScanId,
        'scanned_at' => now()->toIso8601String(),
    ], $headers)->assertStatus(200);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('activity_log')
            ->where('tenant_id', $this->tenantId)
            ->where('description', 'like', '%/check-ins')
            ->orderBy('created_at')
            ->get()
            ->all(),
    );

    expect($entries)->toHaveCount(2);

    $results = array_map(
        fn (object $entry): string => json_decode((string) $entry->properties, true)['result'],
        $entries,
    );

    expect($results)->toBe(['accepted', 'duplicate'])
        ->and($entries[0]->causer_id)->not->toBeNull();
});

it('replays the same (device_id, client_scan_id) pair as 200 with the original result and no new writes', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');
    $headers = recordScanManageHeaders($this->tenantId);
    $clientScanId = (string) Str::uuid7();

    $first = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => $clientScanId,
        'scanned_at' => now()->toIso8601String(),
    ], $headers);
    $first->assertStatus(201);
    $firstCheckInId = $first->json('check_in_id');

    $replay = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => $clientScanId,
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $replay->assertStatus(200)->assertConformsToOpenApi();
    $replay->assertJsonPath('check_in_id', $firstCheckInId);
    $replay->assertJsonPath('result', 'accepted');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('check_ins')->where('ticket_id', $ticketId)->count(),
    );
    expect($rows)->toBe(1);
});

it('rejects a tampered signature with qr_signature_invalid', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'wrong-secret');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'qr_signature_invalid');
});

it('rejects a key revoked after rotation with revoke_previous', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    $headers = recordScanManageHeaders($this->tenantId);

    // Rotate through the endpoint so the plan's "rotated-keys matrix" is
    // exercised via the same rotation surface Task 7 shipped.
    $seed = $this->postJson('/v1/events/'.$eventId.'/signing-keys', [], $headers);
    $seed->assertStatus(201);
    $seedKey = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $seed->json('id'))->firstOrFail(),
    );

    $payload = recordScanPayload($ticketId, $eventId, 0, $seedKey->secret);

    $this->postJson('/v1/events/'.$eventId.'/signing-keys', ['revoke_previous' => true], $headers)->assertStatus(201);

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'qr_key_revoked');
});

it('still validates a payload signed under an earlier version after a plain rotation', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    $headers = recordScanManageHeaders($this->tenantId);

    $seed = $this->postJson('/v1/events/'.$eventId.'/signing-keys', [], $headers);
    $seed->assertStatus(201);
    $seedKey = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $seed->json('id'))->firstOrFail(),
    );

    $payload = recordScanPayload($ticketId, $eventId, 0, $seedKey->secret);

    $this->postJson('/v1/events/'.$eventId.'/signing-keys', [], $headers)->assertStatus(201);

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $response->assertStatus(201)->assertConformsToOpenApi();
    $response->assertJsonPath('result', 'accepted');
});

it('rejects a QR signed with a key the event never issued as qr_signature_invalid', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'never-issued-secret');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'qr_signature_invalid');
});

it('rejects a stale rotation counter with ticket_rotation_stale', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId, ['qr_rotation_counter' => 2]);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'ticket_rotation_stale');
});

it('rejects an unknown ticket with ticket_not_found', function (): void {
    ['eventId' => $eventId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload((string) Str::uuid7(), $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(404)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'ticket_not_found');
});

it('rejects a canceled ticket with ticket_canceled', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId, ['status' => TicketStatus::Canceled]);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(409)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'ticket_canceled');
});

it('rejects a refunded ticket with ticket_refunded', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId, ['status' => TicketStatus::Refunded]);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(409)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'ticket_refunded');
});

it('rejects a scanned_at far in the future with scanned_at_in_future', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->addHour()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422)->assertConformsToOpenApi();
    $response->assertJsonPath('code', 'scanned_at_in_future');
});

it('allows a checkin.scan caller assigned to the event', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    ['headers' => $headers] = recordScanAssignedScanner($this->tenantId, $eventId);

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $response->assertStatus(201)->assertConformsToOpenApi();
});

it('rejects a checkin.scan caller with no assignment for the event with checkin_not_assigned', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CheckinScan),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'checkin_not_assigned');
});

it('rejects a caller with no checkin capability at all with checkin_not_assigned', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], $headers);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'checkin_not_assigned');
});

it('requires a bearer token', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ]);

    $response->assertStatus(401);
});

it('validates the request body', function (): void {
    $response = $this->postJson('/v1/check-ins', [], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'request.validation_failed');
});

it('rejects a non-uuid client_scan_id with a validation problem', function (): void {
    ['eventId' => $eventId, 'ticketId' => $ticketId] = recordScanTicket($this->tenantId);
    recordScanKey($this->tenantId, $eventId, 'secret-1');
    $payload = recordScanPayload($ticketId, $eventId, 0, 'secret-1');

    $response = $this->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => 'not-a-uuid',
        'scanned_at' => now()->toIso8601String(),
    ], recordScanManageHeaders($this->tenantId));

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'request.validation_failed');
});
