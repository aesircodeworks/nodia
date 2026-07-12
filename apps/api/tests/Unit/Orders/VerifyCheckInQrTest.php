<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\VerifyCheckInQr;
use App\Orders\Data\QrVerificationResultData;
use App\Orders\Data\VerifyCheckInQrData;
use App\Orders\Enums\QrVerificationOutcome;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Task 8: the QR verification Action's typed failure
 * branches (signature trial newest-first, revoked-only classification,
 * rotation-counter currency, ticket-status mapping), tested before
 * Task 10's RecordScan consumes it.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('event_signing_keys')->where('tenant_id', $this->tenantId)->delete();
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
});

function verifyQrTicket(string $tenantId, array $overrides = []): array
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

        return [$event->id, $ticket];
    });
}

function verifyQrKey(string $tenantId, string $eventId, int $version, SigningKeyStatus $status, string $secret): EventSigningKey
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

function verifyQrPayload(string $ticketId, string $eventId, int $rotation, string $secret): string
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

function verifyQr(string $payload): QrVerificationResultData
{
    return app(VerifyCheckInQr::class)(new VerifyCheckInQrData($payload));
}

it('verifies a payload signed under the active key', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::Valid)
        ->and($result->ticketId)->toBe($ticket->id)
        ->and($result->eventId)->toBe($eventId)
        ->and($result->rotationCounter)->toBe(0);
});

it('verifies a payload signed under an older retired key when the active key does not match', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Retired, 'retired-secret');
    verifyQrKey($this->tenantId, $eventId, 2, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'retired-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::Valid)
        ->and($result->ticketId)->toBe($ticket->id);
});

it('classifies a payload matching only a revoked key as qr_key_revoked', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Revoked, 'revoked-secret');
    verifyQrKey($this->tenantId, $eventId, 2, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'revoked-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::KeyRevoked)
        ->and($result->ticketId)->toBeNull()
        ->and($result->eventId)->toBeNull();
});

it('classifies a payload matching no key at all as qr_signature_invalid', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'wrong-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::SignatureInvalid);
});

it('classifies a payload that cannot be decoded as qr_signature_invalid', function (): void {
    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr('not-a-payload'));

    expect($result->outcome)->toBe(QrVerificationOutcome::SignatureInvalid);
});

it('classifies a verified payload for an unknown ticket as ticket_not_found', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload((string) Str::uuid7(), $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::TicketNotFound);
});

it('classifies a stale rotation counter as ticket_rotation_stale', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId, ['qr_rotation_counter' => 2]);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::RotationStale)
        ->and($result->ticketId)->toBe($ticket->id)
        ->and($result->rotationCounter)->toBe(2);
});

it('classifies a canceled ticket as ticket_canceled', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId, ['status' => TicketStatus::Canceled]);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::TicketCanceled);
});

it('classifies a refunded ticket as ticket_refunded', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId, ['status' => TicketStatus::Refunded]);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::TicketRefunded);
});

it('classifies an out-of-enum ticket status as ticket_status_unknown instead of throwing', function (): void {
    [$eventId, $ticket] = verifyQrTicket($this->tenantId);
    verifyQrKey($this->tenantId, $eventId, 1, SigningKeyStatus::Active, 'active-secret');

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'bogus']),
    );

    $payload = verifyQrPayload($ticket->id, $eventId, 0, 'active-secret');

    $result = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => verifyQr($payload));

    expect($result->outcome)->toBe(QrVerificationOutcome::TicketStatusUnknown);
});
