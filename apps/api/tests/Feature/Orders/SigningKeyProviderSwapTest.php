<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\RotateSigningKey;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Orders\Support\DerivedTicketSigningKeyProvider;
use App\Orders\Support\EventSigningKeyProvider;
use App\Orders\Support\TicketQrCodec;
use App\Orders\Support\TicketSigningKeyProvider;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 2 deployment-transition test: a QR payload signed
 * by the Stage 7 derived-key provider before this stage's provider swap
 * verifies against the stored version 1 key after it, because
 * GetOrCreateActiveSigningKey seeds version 1 from the exact same HKDF
 * derivation. The container's TicketSigningKeyProvider binding is the
 * only thing that changes; TicketQrCodec and the payload format are
 * untouched (stage-09 plan, Dependencies and Task 5). Trial verification
 * across retired-versus-revoked keys (the mechanism Task 8's QR
 * verification Action builds on to make plain rotation non-invalidating
 * while revoke_previous is invalidating) is out of this task's scope
 * per the plan's own task split; this test proves the two ingredients
 * that mechanism depends on: the pre-swap payload's signing material is
 * preserved byte-for-byte through a plain rotation, and only
 * revoke_previous flips its status to the excluded (revoked) state.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->ticket = app(TenantTransaction::class)->asTenant($this->tenantId, function (): Ticket {
        $event = Event::factory()->create(['tenant_id' => $this->tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ]);

        return Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            'qr_rotation_counter' => 0,
        ]);
    });
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

it('verifies a pre-swap payload against the seeded version 1 key, and only revoke_previous rotation invalidates its key', function (): void {
    // Pre-swap: signed the way Stage 7 rendered every QR before this
    // stage existed, through the raw HKDF-derived provider.
    $preSwapCodec = new TicketQrCodec(new DerivedTicketSigningKeyProvider);
    $payload = $preSwapCodec->sign($this->ticket);

    // Post-swap: the container now resolves TicketSigningKeyProvider to
    // EventSigningKeyProvider, which seeds the event's version 1 key
    // from the exact same derivation on first use.
    $postSwapCodec = app(TicketQrCodec::class);

    expect(app(TicketSigningKeyProvider::class))->toBeInstanceOf(EventSigningKeyProvider::class);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($postSwapCodec, $payload): void {
        expect($postSwapCodec->verify($payload)->valid)->toBeTrue();
    });

    $seeded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('event_id', $this->ticket->event_id)->where('key_version', 1)->firstOrFail(),
    );

    $expectedSecret = (new DerivedTicketSigningKeyProvider)->keyForEvent($this->ticket->event_id);
    expect($seeded->secret)->toBe($expectedSecret);

    // A plain rotation preserves the version 1 key's material and marks
    // it merely retired: the future trial-verification Action (Task 8)
    // will still trust retired keys, so a pre-swap payload keeps
    // verifying across an ordinary rotation.
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RotateSigningKey::class))($this->ticket->event_id),
    );

    $afterPlainRotation = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $seeded->id)->firstOrFail(),
    );

    expect($afterPlainRotation->status)->toBe(SigningKeyStatus::Retired)
        ->and($afterPlainRotation->secret)->toBe($expectedSecret);

    // A rotation with revoke_previous: true against the now-active key
    // marks it revoked, the state the future trial-verification Action
    // excludes: this is the mechanism by which "only revoke_previous
    // invalidates" a previously rendered payload.
    $activeBeforeRevoke = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('event_id', $this->ticket->event_id)->where('status', SigningKeyStatus::Active)->firstOrFail(),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RotateSigningKey::class))($this->ticket->event_id, revokePrevious: true),
    );

    $afterRevocation = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('id', $activeBeforeRevoke->id)->firstOrFail(),
    );

    expect($afterRevocation->status)->toBe(SigningKeyStatus::Revoked)
        ->and($afterRevocation->revoked_at)->not->toBeNull();
});
