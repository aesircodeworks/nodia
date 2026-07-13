<?php

use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\ClaimToken;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Inventory\Support\OnSaleQueue;
use App\Models\User;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Models\EventSigningKey;
use App\Orders\Models\Order;
use App\Orders\Models\PromoCode;
use App\Orders\Models\Ticket;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Models\Payout;
use App\Payments\Models\SubmerchantAccount;
use App\Payments\Support\CircuitBreaker;
use App\Reporting\Models\Export;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Tests\Support\MigratedDatabase;
use Tests\Support\OpenApiSpec;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TotpCodes;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');
    Queue::fake();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // The MFA exercisers (task breakdown item 11) confirm MFA for several
    // contract-mfa-*@example.com users, which are otherwise kept forever
    // the same way contract-staff@example.com is; their mfa_recovery_codes
    // rows would otherwise dangle and block an unrelated later suite's
    // blanket User::query()->delete() the same way a stale memberships row
    // would (see the comment below).
    DB::table('mfa_recovery_codes')->delete();

    // ledger_entries is append-only (DELETE raises); the ledger exercisers
    // seed rows, so teardown truncates on the owning test connection.
    DB::statement('truncate ledger_entries');

    // contractPlatformBearer() recreates its membership and role idempotently
    // per call (its own docblock), so deleting them here every test is safe
    // and, unlike the contract-platform-staff/contract-incapable-staff User
    // rows deliberately kept by firstOrCreate, prevents a dangling
    // memberships row from blocking an unrelated later suite's blanket
    // User::query()->delete() (observed against StaffRefreshRotationContentionTest).
    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('outbox_deliveries')->delete();
        DB::table('outbox_events')->delete();
        DB::table('memberships')->delete();

        // The webhook exercisers (stage-08a plan, task breakdown item 6)
        // persist raw rows under the sentinel tenant.
        DB::table('gateway_webhook_events')->delete();
    });

    // contractRoleTenantBearer() (task breakdown item 8) writes tenant-scope
    // memberships into contractRoleTenant()'s own row, not the sentinel
    // tenant, so those also need clearing before the tenant delete below can
    // succeed: memberships.tenant_id carries no cascade.
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();

            // The submerchant-account exercisers (stage-08c plan, task
            // breakdown item 4) write submerchant_accounts referencing the
            // tenant with no cascade, so they go ahead of the tenant delete.
            DB::table('submerchant_accounts')->where('tenant_id', $tenantId)->delete();

            // The payout exercisers (stage-08c plan, task breakdown item 9)
            // write payouts referencing the tenant with no cascade, same
            // reasoning as submerchant_accounts above.
            DB::table('payouts')->where('tenant_id', $tenantId)->delete();

            // The refund exercisers (stage-08b plan, task breakdown item 8)
            // write refunds referencing payments with no cascade, so they go
            // ahead of payments; the ledger projection rides the sync queue
            // only after the stability window, so ledger_entries stays empty
            // here.
            DB::table('refunds')->where('tenant_id', $tenantId)->delete();

            // The payment exercisers (stage-08a plan, task breakdown items
            // 3 to 6) write payments referencing orders with no cascade, so
            // they go ahead of orders.
            DB::table('payments')->where('tenant_id', $tenantId)->delete();

            // The order exercisers (stage-07 plan, task breakdown item 3)
            // write orders, order_items, and tickets referencing customers,
            // events, ticket_types, and holds with no cascade, so they go
            // first.
            // The check-in-assignment exercisers (stage-09 plan, Endpoints
            // "Check-in assignments") write check_in_assignments
            // referencing events with no cascade, so they go ahead of
            // events below.
            DB::table('check_in_assignments')->where('tenant_id', $tenantId)->delete();

            // The check-ins exercisers (stage-09 plan, Endpoints "POST
            // /v1/check-ins") write check_ins referencing tickets with no
            // cascade, so they go first.
            DB::table('check_ins')->where('tenant_id', $tenantId)->delete();
            DB::table('event_signing_keys')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('promo_codes')->where('tenant_id', $tenantId)->delete();

            // The export exercisers (stage-11 plan, task breakdown item
            // 16) write exports scoped to a fresh contractHoldTenant()
            // each; exports.tenant_id carries a real foreign key to
            // tenants with no cascade, so it must be deleted before this
            // tenant is deleted below.
            DB::table('exports')->where('tenant_id', $tenantId)->delete();

            // contractEventCoverMedia()'s exercisers (stage-05c plan, task
            // breakdown item 2) write media rows scoped to a fresh event
            // under contractEventMediaTenant(); media.tenant_id carries a
            // real foreign key to tenants with no cascade, so it must be
            // deleted before this tenant is deleted below.
            DB::table('media')->where('tenant_id', $tenantId)->delete();

            // contractHoldBearer()'s exercisers (stage-06 plan, task
            // breakdown item 4) write holds and hold_items scoped to a
            // fresh contractHoldTenant() each; hold_items and holds must be
            // deleted ahead of ticket_types and events below since
            // hold_items.ticket_type_id and holds.event_id both reference
            // them with no cascade.
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();

            // The data subject request exercisers (stage-12 plan, task
            // breakdown item 3) write data_subject_requests referencing
            // customers with no cascade, so they go ahead of the customers
            // delete below.
            DB::table('data_subject_requests')->where('tenant_id', $tenantId)->delete();

            // The customer token/registration/claim exercisers (task
            // breakdown item 13) create customers rows scoped to a fresh
            // contractCustomerTenant() each; customers carries no
            // platform write policy either, the same reason memberships
            // needs this per-tenant nodia_app delete above rather than a
            // blanket nodia_platform one. Deleted after holds and orders:
            // the stage-07 conversion exercisers attach customers to both.
            DB::table('customers')->where('tenant_id', $tenantId)->delete();

            // contractSeatedEvent()'s exercisers (stage-06 plan, task
            // breakdown item 11) materialize event_seats scoped to a fresh
            // seated event; event_seats must be deleted ahead of
            // ticket_types below since event_seats.ticket_type_id
            // references them with no cascade.
            DB::table('event_seats')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();

            // contractTicketTypeBearer()'s exercisers (stage-05a plan, task
            // breakdown item 8) write ticket_types scoped to a fresh event
            // under contractEventTenant(); ticket_types must be deleted
            // ahead of events below since ticket_types.event_id references
            // events.
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();

            // contractQueueEvent()'s exercisers (stage-10 plan, TDD
            // sequencing Slice 4, task breakdown item 7) write Redis queue
            // state keyed by tenant_id and event_id
            // (App\Inventory\Support\OnSaleQueue), never Postgres; purged
            // here ahead of the events delete below since the waiting-set
            // and active-set keys are derived from each event's own id.
            $queueEventIds = DB::table('events')->where('tenant_id', $tenantId)->pluck('id')->all();

            foreach ($queueEventIds as $queueEventId) {
                Redis::connection()->del(OnSaleQueue::waitingKey($tenantId, $queueEventId));
                Redis::connection()->srem('onsale:active', OnSaleQueue::activeMember($tenantId, $queueEventId));
            }

            foreach (Redis::connection()->keys('onsale:'.$tenantId.':entrant:*') as $queueEntrantKey) {
                Redis::connection()->del($queueEntrantKey);
            }

            // contractEventBearer()'s exercisers (stage-05a plan, task
            // breakdown item 5) write events scoped to a fresh
            // contractEventTenant() each; events must be deleted ahead of
            // venues below since events.venue_id references venues.
            DB::table('events')->where('tenant_id', $tenantId)->delete();

            // contractSeatMapBearer()'s exercisers (stage-05b plan, task
            // breakdown item 2) write seat_maps (seats cascade) scoped to a
            // fresh venue under contractSeatMapTenant(); seat_maps must be
            // deleted ahead of venues below since seat_maps.venue_id
            // references venues on delete restrict.
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();

            // contractVenueBearer()'s exercisers (stage-05a plan, task
            // breakdown item 3) write venues scoped to a fresh
            // contractVenueTenant() each; venues carries no platform
            // write policy either, the same reason as customers above.
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * Reused across dataset iterations the same way contractStaffUser() is:
 * firstOrCreate so repeated calls in this file reuse the same row instead
 * of colliding on the unique email, and the membership lookup below is
 * idempotent for the same reason (task breakdown item 7: the
 * tenancy.platform group now requires a bearer holding tenants.manage).
 * Platform-scope memberships are unconditionally MFA-enforcing (task
 * breakdown item 11), so the reused row is created MFA-confirmed from the
 * start rather than flipped afterward the way
 * Tests\Support\PlatformStaff::token() does for a fresh row each call:
 * firstOrCreate would otherwise reuse a row whose MFA got confirmed by an
 * earlier dataset iteration, and the plain password-only exchange below
 * would then fail with mfa_required on every call after the first. A
 * fresh TOTP code for the persisted secret is computed on every call
 * instead, since a stale code would fail once its window passes.
 *
 * users.mfa_secret, mfa_enabled, and mfa_confirmed_at are deliberately
 * absent from User's #[Fillable(...)] list (only name/email/password are
 * mass-assignable), so setting them through User::factory()->raw() merged
 * into firstOrCreate()'s create attributes would be silently dropped;
 * forceFill() is required here, the same way every MFA Action
 * (App\Identity\Actions\EnrollMfa and friends) already sets them.
 */
function contractPlatformBearer(Capability $capability = Capability::TenantsManage): string
{
    $email = $capability === Capability::TenantsManage
        ? 'contract-platform-staff@example.com'
        : 'contract-incapable-staff@example.com';

    $google2fa = new Google2FA;

    $user = User::query()->firstOrCreate(['email' => $email], User::factory()->raw(['email' => $email]));

    if (! $user->mfa_enabled) {
        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => $google2fa->generateSecretKey(),
            'mfa_confirmed_at' => now(),
        ])->save();
    }

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'password',
        'mfa_code' => $google2fa->getCurrentOtp($user->mfa_secret),
    ]);

    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asPlatform(function () use ($user, $capability, $sentinel): void {
        $hasMembership = Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $sentinel)
            ->exists();

        if (! $hasMembership) {
            Membership::factory()->platform()->create([
                'user_id' => $user->id,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $sentinel,
                    'capabilities' => [$capability->value],
                ])->id,
            ]);
        }
    });

    /** @var string $token */
    $token = $response->json('access_token');

    return $token;
}

function contractTenant(): Tenant
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['default_locale' => 'en', 'supported_locales' => ['en']]),
    );
}

function contractDomain(array $attributes = []): TenantDomain
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::factory()->create($attributes),
    );
}

/**
 * A fresh tenant per call for the roles endpoint exercisers (task
 * breakdown item 8): unlike contractTenant()'s reuse-by-value callers,
 * several role exercisers need a tenant with no pre-existing custom roles
 * so role_name_taken and pagination assertions stay deterministic.
 */
function contractRoleTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractRoleBearer(Tenant $tenant, array $capabilities = ['roles.manage']): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    $token = $response->json('access_token');

    return $token;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractRole(Tenant $tenant, array $attributes = []): Role
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Role::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

function contractTemplateRoleId(): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail()->id,
    );
}

/**
 * A fresh tenant per call, mirroring contractRoleTenant()'s own
 * precedent (stage-05a plan, task breakdown item 3).
 */
function contractVenueTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractVenueBearer(Tenant $tenant, array $capabilities = ['events.view', 'events.manage']): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    $token = $response->json('access_token');

    return $token;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractVenue(Tenant $tenant, array $attributes = []): Venue
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Venue::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

/**
 * A fresh tenant per call, mirroring contractVenueTenant()'s own precedent
 * (stage-05b plan, task breakdown item 2).
 */
function contractSeatMapTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractSeatMapBearer(Tenant $tenant, array $capabilities = ['seat_maps.manage']): string
{
    return contractVenueBearer($tenant, $capabilities);
}

/**
 * @return array<string, mixed>
 */
function contractSeatMapCreatePayload(array $overrides = []): array
{
    return [
        'name' => 'Contract Lower Bowl',
        'layout' => ['stage' => 'north'],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
        ...$overrides,
    ];
}

/**
 * A seat map row created directly (not through the POST endpoint), for
 * exercisers that only read it (stage-05b plan, task breakdown item 3),
 * mirroring contractVenue()'s own precedent.
 *
 * @param  array<string, mixed>  $attributes
 */
function contractSeatMap(Tenant $tenant, Venue $venue, array $attributes = []): SeatMap
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => SeatMap::factory()->create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id, ...$attributes]),
    );
}

/**
 * A fresh tenant per call, mirroring contractVenueTenant()'s own precedent
 * (stage-05a plan, task breakdown item 5).
 */
function contractEventTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractEventBearer(Tenant $tenant, array $capabilities = ['events.view', 'events.manage']): string
{
    return contractVenueBearer($tenant, $capabilities);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractEvent(Tenant $tenant, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Event::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

/**
 * @return array<string, mixed>
 */
function contractEventCreatePayload(array $overrides = []): array
{
    return [
        'name' => ['en' => 'Contract Gala'],
        'description' => ['en' => 'Contract description.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/contract',
        'start_at' => '2026-09-01T18:00:00Z',
        'end_at' => '2026-09-01T21:00:00Z',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

/**
 * A fresh tenant per call, mirroring contractEventTenant()'s own precedent
 * (stage-05a plan, task breakdown item 8).
 */
function contractTicketTypeTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractTicketTypeBearer(Tenant $tenant, array $capabilities = ['events.view', 'events.manage']): string
{
    return contractVenueBearer($tenant, $capabilities);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractTicketType(Tenant $tenant, string $eventId, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $eventId, ...$attributes]),
    );
}

/**
 * A published, seated event with one materialized, available,
 * requires_seat event_seat (stage-06 plan, task breakdown item 11),
 * mirroring tests/Feature/Inventory/HoldEndpointsTest.php's own
 * holdSeatedTicketType fixture.
 *
 * @return array{event: Event, ticketType: TicketType, eventSeatId: string}
 */
function contractSeatedEvent(Tenant $tenant): array
{
    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenant->id]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id]);
        $event = Event::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EventStatus::Published,
            'venue_id' => $venue->id,
            'seat_map_id' => $seatMap->id,
            'is_virtual' => false,
            'virtual_event_url' => null,
        ]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'requires_seat' => true]);
        TicketTypeInventory::factory()->create(['tenant_id' => $tenant->id, 'ticket_type_id' => $ticketType->id, 'quantity' => 1, 'held' => 0, 'sold' => 0]);
        $templateSeat = Seat::factory()->create(['tenant_id' => $tenant->id, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => '1']);
        $eventSeat = EventSeat::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'seat_id' => $templateSeat->id,
            'ticket_type_id' => $ticketType->id,
            'status' => EventSeatStatus::Available,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType, 'eventSeatId' => $eventSeat->id];
    });
}

/**
 * @return array<string, mixed>
 */
function contractTicketTypeCreatePayload(array $overrides = []): array
{
    return [
        'name' => 'Contract General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        ...$overrides,
    ];
}

/**
 * A fresh tenant plus a resolvable tenant_domains row (stage-06 plan,
 * task breakdown item 4), mirroring contractCustomerTenant()'s own
 * precedent since the storefront hold endpoints resolve tenant from
 * Host, never X-Tenant-Id.
 *
 * @return array{tenant: Tenant, host: string}
 */
function contractHoldTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * A published event with one GA ticket type and its ticket_type_inventory
 * counter row, scoped to the given contractHoldTenant() (stage-06 plan,
 * task breakdown item 4). $eventAttributes merges over the factory's own
 * defaults, e.g. a flagged on_sale_policy (stage-10 plan).
 *
 * @param  array<string, mixed>  $eventAttributes
 * @return array{event: Event, ticketType: TicketType}
 */
function contractHoldFixture(Tenant $tenant, int $quantity = 10, array $eventAttributes = []): array
{
    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $quantity, $eventAttributes): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published, ...$eventAttributes]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });
}

/**
 * A published, high-demand-flagged event scoped to the given
 * contractHoldTenant() (stage-10 plan, TDD sequencing Slice 4, task
 * breakdown item 7).
 */
function contractQueueEvent(Tenant $tenant, bool $challengeRequired = false): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Event::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EventStatus::Published,
            'on_sale_policy' => new OnSalePolicyData(highDemand: true, admissionRatePerMinute: null, challengeRequired: $challengeRequired),
        ]),
    );
}

/**
 * A fresh tenant per call, mirroring contractTicketTypeTenant()'s own
 * precedent (stage-05c plan, task breakdown item 2).
 */
function contractEventMediaTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractEventMediaBearer(Tenant $tenant, array $capabilities = ['events.view', 'events.manage']): string
{
    return contractVenueBearer($tenant, $capabilities);
}

/**
 * Attaches a real cover file through the actual medialibrary upload path
 * (Storage::fake('media'), this file's own beforeEach) so DELETE and GET
 * exercisers have a genuine row to act on, mirroring contractTicketType()'s
 * own precedent of writing through the real model rather than a raw insert.
 */
function contractEventCoverMedia(Tenant $tenant, string $eventId): SpatieMedia
{
    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($eventId): SpatieMedia {
        $event = Event::query()->findOrFail($eventId);

        return $event->addMedia(UploadedFile::fake()->image('contract-cover.jpg'))->toMediaCollection('cover');
    });
}

/**
 * A fresh tenant per call, mirroring contractRoleTenant()'s own precedent
 * (task breakdown item 9): several membership exercisers need a tenant
 * with no pre-existing memberships beyond the bearer's own, so
 * membership_exists and last_owner_removal assertions stay deterministic.
 */
function contractMembershipTenant(): Tenant
{
    return contractTenant();
}

/**
 * @param  list<string>  $capabilities
 */
function contractMembershipBearer(Tenant $tenant, array $capabilities = ['memberships.manage']): string
{
    return contractRoleBearer($tenant, $capabilities);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractMembership(Tenant $tenant, array $attributes = []): Membership
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id])->id,
            ...$attributes,
        ]),
    );
}

/**
 * A user with a plain (no-capability) membership in $tenant, for the
 * check-in assignment exercisers: POST /v1/events/{event}/check-in-assignments
 * requires the target user_id to already be a member (stage-09 plan,
 * Endpoints "Check-in assignments").
 */
function contractCheckInAssignmentMember(Tenant $tenant): string
{
    return contractMembership($tenant)->user_id;
}

function contractStaffUser(): User
{
    // firstOrCreate so repeated calls across dataset iterations in this
    // file (contract tests do not truncate `users` between cases) reuse
    // the same row instead of colliding on the unique email.
    return User::query()->firstOrCreate(
        ['email' => 'contract-staff@example.com'],
        User::factory()->raw(['email' => 'contract-staff@example.com']),
    );
}

function contractStaffBearer(): string
{
    return contractStaffTokenPair()['access_token'];
}

/**
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
 */
function contractStaffTokenPair(): array
{
    contractStaffUser();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => 'contract-staff@example.com',
        'password' => 'password',
    ]);

    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $pair */
    $pair = $response->json();

    return $pair;
}

/**
 * A fresh, unenrolled staff bearer for one MFA exerciser (stage-03 plan,
 * task breakdown item 11). Each exerciser below gets its own email, never
 * reused across cases the way contractStaffUser() deliberately is, since
 * MFA enrollment is stateful and the different exercisers need different
 * starting states (unenrolled, pending, confirmed).
 */
function contractMfaBearer(string $email): string
{
    $user = User::query()->firstOrCreate(['email' => $email], User::factory()->raw(['email' => $email]));

    return test()->postJson('/v1/auth/staff/token', ['email' => $user->email, 'password' => 'password'])
        ->json('access_token');
}

/**
 * Enrolls and confirms MFA for a fresh bearer through the real endpoints,
 * returning the bearer and the confirmed secret so an exerciser can
 * compute a currently valid TOTP code.
 *
 * @return array{0: string, 1: string} token, secret
 */
function contractMfaConfirmedBearer(string $email): array
{
    $token = contractMfaBearer($email);
    $headers = ['Authorization' => 'Bearer '.$token];

    $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');
    test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);

    return [$token, $secret];
}

/**
 * A fresh tenant plus a resolvable tenant_domains row per call (task
 * breakdown item 13): the customer surface is host-resolved
 * (tenancy.storefront), never X-Tenant-Id, so every customer exerciser
 * below needs a real Host to hit rather than a bearer.
 *
 * @return array{0: Tenant, 1: string} tenant, host
 */
function contractCustomerTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $domain->domain];
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractCustomer(Tenant $tenant, array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

/**
 * ADR 019: conformance assertions only gate the responses tests exercise, so
 * every response documented in docs/openapi/openapi.yaml must register an
 * exerciser here that produces it. Documenting a new response without one
 * fails the coverage test until the exerciser (and the shape it proves) lands.
 *
 * Error-path exercisers stay schema-valid on the request side (the request
 * schemas are deliberately loose there) so assertConformsToOpenApi can
 * assert both directions on every documented response.
 *
 * @return array<string, Closure(): TestResponse<JsonResponse>>
 */
function documentedResponseExercisers(): array
{
    return [
        'get /v1/health 200' => fn (): TestResponse => test()->getJson('/v1/health'),
        'get /v1/health 503' => function (): TestResponse {
            config()->set('database.redis.health.host', '127.0.0.1');
            config()->set('database.redis.health.port', 1);

            return test()->getJson('/v1/health');
        },
        'post /v1/tenants 201' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer()]),
        'post /v1/tenants 401' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ]),
        'post /v1/tenants 403' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => 'Contract Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)]),
        'post /v1/tenants 422' => fn (): TestResponse => test()->postJson('/v1/tenants', [
            'name' => '',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ], ['Authorization' => 'Bearer '.contractPlatformBearer()]),
        'get /v1/tenants 200' => function (): TestResponse {
            contractTenant();

            return test()->getJson('/v1/tenants', ['Authorization' => 'Bearer '.contractPlatformBearer()]);
        },
        'get /v1/tenants 400' => fn (): TestResponse => test()->getJson(
            '/v1/tenants?sort=payout_schedule',
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants 401' => fn (): TestResponse => test()->getJson('/v1/tenants'),
        'get /v1/tenants 403' => fn (): TestResponse => test()->getJson(
            '/v1/tenants',
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'get /v1/tenants/{tenant} 200' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.contractTenant()->id,
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants/{tenant} 401' => fn (): TestResponse => test()->getJson('/v1/tenants/'.contractTenant()->id),
        'get /v1/tenants/{tenant} 403' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.contractTenant()->id,
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'get /v1/tenants/{tenant} 404' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.Str::uuid7(),
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 200' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 401' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
        ),
        'patch /v1/tenants/{tenant} 403' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['name' => 'Renamed Contract Tenant'],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'patch /v1/tenants/{tenant} 404' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.Str::uuid7(),
            ['name' => 'Ghost'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenants/{tenant} 422' => fn (): TestResponse => test()->patchJson(
            '/v1/tenants/'.contractTenant()->id,
            ['default_locale' => 'fr'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/media 201' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->post(
                "/v1/tenants/{$tenant->id}/media",
                ['file' => UploadedFile::fake()->image('contract-logo.jpg'), 'collection' => 'logo'],
                [
                    'Authorization' => 'Bearer '.contractPlatformBearer(),
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/tenants/{tenant}/media 401' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->post(
                "/v1/tenants/{$tenant->id}/media",
                ['file' => UploadedFile::fake()->image('contract-logo.jpg'), 'collection' => 'logo'],
                ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
            );
        },
        'post /v1/tenants/{tenant}/media 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->post(
                "/v1/tenants/{$tenant->id}/media",
                ['file' => UploadedFile::fake()->image('contract-logo.jpg'), 'collection' => 'logo'],
                [
                    'Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView),
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/tenants/{tenant}/media 404' => fn (): TestResponse => test()->post(
            '/v1/tenants/'.Str::uuid7().'/media',
            ['file' => UploadedFile::fake()->image('contract-logo.jpg'), 'collection' => 'logo'],
            [
                'Authorization' => 'Bearer '.contractPlatformBearer(),
                'Content-Type' => 'multipart/form-data',
                'Accept' => 'application/json',
            ],
        ),
        'post /v1/tenants/{tenant}/media 413' => function (): TestResponse {
            $tenant = contractTenant();
            $token = contractPlatformBearer();

            return test()->call(
                'POST',
                "/v1/tenants/{$tenant->id}/media",
                ['collection' => 'logo'],
                [],
                ['file' => UploadedFile::fake()->image('contract-logo.jpg')],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'CONTENT_TYPE' => 'multipart/form-data',
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
                ],
            );
        },
        'post /v1/tenants/{tenant}/media 422' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->post(
                "/v1/tenants/{$tenant->id}/media",
                ['file' => UploadedFile::fake()->image('contract-logo.jpg'), 'collection' => 'banner'],
                [
                    'Authorization' => 'Bearer '.contractPlatformBearer(),
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/tenants/{tenant}/domains 201' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/domains 401' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
        ),
        'post /v1/tenants/{tenant}/domains 403' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'contract.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'post /v1/tenants/{tenant}/domains 404' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.Str::uuid7().'/domains',
            ['domain' => 'ghost.example.com'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'post /v1/tenants/{tenant}/domains 409' => function (): TestResponse {
            $domain = contractDomain(['domain' => 'taken.example.com']);

            return test()->postJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['domain' => 'taken.example.com'],
                ['Authorization' => 'Bearer '.contractPlatformBearer()],
            );
        },
        'post /v1/tenants/{tenant}/domains 422' => fn (): TestResponse => test()->postJson(
            '/v1/tenants/'.contractTenant()->id.'/domains',
            ['domain' => 'not a hostname'],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'get /v1/tenants/{tenant}/domains 200' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['Authorization' => 'Bearer '.contractPlatformBearer()],
            );
        },
        'get /v1/tenants/{tenant}/domains 401' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson('/v1/tenants/'.$domain->tenant_id.'/domains');
        },
        'get /v1/tenants/{tenant}/domains 403' => function (): TestResponse {
            $domain = contractDomain();

            return test()->getJson(
                '/v1/tenants/'.$domain->tenant_id.'/domains',
                ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
            );
        },
        'get /v1/tenants/{tenant}/domains 404' => fn (): TestResponse => test()->getJson(
            '/v1/tenants/'.Str::uuid7().'/domains',
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 200' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 401' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 403' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 404' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.Str::uuid7(),
            ['is_primary' => true],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'patch /v1/tenant-domains/{tenant_domain} 422' => fn (): TestResponse => test()->patchJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            ['is_primary' => false],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 401' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain()->id,
        ),
        'delete /v1/tenant-domains/{tenant_domain} 403' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain()->id,
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer(Capability::EventsView)],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 404' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.Str::uuid7(),
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        'delete /v1/tenant-domains/{tenant_domain} 409' => fn (): TestResponse => test()->deleteJson(
            '/v1/tenant-domains/'.contractDomain(['is_primary' => true])->id,
            [],
            ['Authorization' => 'Bearer '.contractPlatformBearer()],
        ),
        // The 204 documents no content, so it has no coverage key here; the
        // feature test conformance-asserts it. The 422 exerciser keeps the
        // request spec-valid (the parameter schema is a loose string) by
        // sending a present-but-malformed domain rather than omitting it.
        'get /v1/internal/domain-verification 404' => fn (): TestResponse => test()->getJson(
            '/v1/internal/domain-verification?domain=unregistered.example.com',
        ),
        'get /v1/internal/domain-verification 422' => fn (): TestResponse => test()->getJson(
            '/v1/internal/domain-verification?domain='.urlencode('not a hostname'),
        ),
        'post /v1/auth/staff/token 200' => function (): TestResponse {
            contractStaffUser();

            return test()->postJson('/v1/auth/staff/token', [
                'email' => 'contract-staff@example.com',
                'password' => 'password',
            ]);
        },
        'post /v1/auth/staff/token 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/token', [
            'email' => 'ghost-contract@example.com',
            'password' => 'wrong-password',
        ]),
        'post /v1/auth/staff/token 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/token', [
            'email' => 'not-an-email',
            'password' => 'x',
        ]),
        'post /v1/auth/staff/refresh 200' => function (): TestResponse {
            $pair = contractStaffTokenPair();

            return test()->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']]);
        },
        'post /v1/auth/staff/refresh 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/refresh', [
            'refresh_token' => 'not-a-real-refresh-token',
        ]),
        'post /v1/auth/staff/refresh 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/refresh', []),
        'post /v1/auth/staff/logout 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/logout'),
        'post /v1/auth/mfa/enrollment 200' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/enrollment', [], [
            'Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-enroll@example.com'),
        ]),
        'post /v1/auth/mfa/enrollment 401' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/enrollment'),
        'post /v1/auth/mfa/enrollment 409' => function (): TestResponse {
            [$token] = contractMfaConfirmedBearer('contract-mfa-already-enrolled@example.com');

            return test()->postJson('/v1/auth/mfa/enrollment', [], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/auth/mfa/enrollment/confirm 200' => function (): TestResponse {
            $token = contractMfaBearer('contract-mfa-confirm@example.com');
            $headers = ['Authorization' => 'Bearer '.$token];
            $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');

            return test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);
        },
        'post /v1/auth/mfa/enrollment/confirm 401' => function (): TestResponse {
            $token = contractMfaBearer('contract-mfa-confirm-wrong-code@example.com');
            $headers = ['Authorization' => 'Bearer '.$token];
            test()->postJson('/v1/auth/mfa/enrollment', [], $headers);

            return test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => '000000'], $headers);
        },
        'post /v1/auth/mfa/enrollment/confirm 409' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/enrollment/confirm',
            ['code' => '000000'],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-confirm-not-enrolled@example.com')],
        ),
        'post /v1/auth/mfa/enrollment/confirm 422' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/enrollment/confirm',
            [],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-confirm-validation@example.com')],
        ),
        'post /v1/auth/mfa/disable 401' => fn (): TestResponse => test()->postJson('/v1/auth/mfa/disable', ['code' => '000000']),
        'post /v1/auth/mfa/disable 403' => function (): TestResponse {
            [$token, $secret] = contractMfaConfirmedBearer('contract-mfa-disable-enforced@example.com');

            $sentinel = config()->string('tenancy.platform_tenant_id');
            $user = User::query()->where('email', 'contract-mfa-disable-enforced@example.com')->firstOrFail();

            app(TenantTransaction::class)->asPlatform(function () use ($user, $sentinel): void {
                Membership::factory()->platform()->create([
                    'user_id' => $user->id,
                    'role_id' => Role::factory()->create(['tenant_id' => $sentinel])->id,
                ]);
            });

            return test()->postJson('/v1/auth/mfa/disable', ['code' => TotpCodes::current($secret)], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/auth/mfa/disable 409' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/disable',
            ['code' => '000000'],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-disable-not-enrolled@example.com')],
        ),
        'post /v1/auth/mfa/disable 422' => fn (): TestResponse => test()->postJson(
            '/v1/auth/mfa/disable',
            [],
            ['Authorization' => 'Bearer '.contractMfaBearer('contract-mfa-disable-validation@example.com')],
        ),
        'get /v1/me 200' => fn (): TestResponse => test()->getJson('/v1/me', [
            'Authorization' => 'Bearer '.contractStaffBearer(),
        ]),
        'get /v1/me 401' => fn (): TestResponse => test()->getJson('/v1/me'),
        'get /v1/roles 200' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles', [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles 400' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles?sort=capabilities', [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles 401' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/roles 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/roles', [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/roles 201' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => ['events.view']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 401' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => []],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 403' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => []],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 409' => function (): TestResponse {
            $tenant = contractRoleTenant();
            contractRole($tenant, ['name' => 'Duplicate Name']);

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Duplicate Name', 'capabilities' => []],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/roles 422' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->postJson(
                '/v1/roles',
                ['name' => 'Contract Role', 'capabilities' => ['not.a.capability']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/roles/{role} 200' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->getJson('/v1/roles/'.$role->id, [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->getJson('/v1/roles/'.$role->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/roles/'.$role->id, [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->getJson('/v1/roles/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/roles/{role} 200' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['name' => 'Renamed Contract Role'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->patchJson(
                '/v1/roles/'.Str::uuid7(),
                ['name' => 'Ghost'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 409' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->patchJson(
                '/v1/roles/'.contractTemplateRoleId(),
                ['name' => 'Hijacked Template'],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/roles/{role} 422' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->patchJson(
                '/v1/roles/'.$role->id,
                ['capabilities' => ['not.a.capability']],
                ['Authorization' => 'Bearer '.contractRoleBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'delete /v1/roles/{role} 401' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->deleteJson('/v1/roles/'.$role->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/roles/{role} 403' => function (): TestResponse {
            $tenant = contractRoleTenant();
            $role = contractRole($tenant);

            return test()->deleteJson('/v1/roles/'.$role->id, [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/roles/{role} 404' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->deleteJson('/v1/roles/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/roles/{role} 409' => function (): TestResponse {
            $tenant = contractRoleTenant();

            return test()->deleteJson('/v1/roles/'.contractTemplateRoleId(), [], [
                'Authorization' => 'Bearer '.contractRoleBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/venues 201' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->postJson(
                '/v1/venues',
                ['name' => 'Contract Arena', 'address' => '1 Contract St', 'city' => 'Austin', 'country' => 'US', 'capacity' => 500],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues 401' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->postJson(
                '/v1/venues',
                ['name' => 'Contract Arena', 'address' => '1 Contract St', 'city' => 'Austin', 'country' => 'US', 'capacity' => 500],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues 403' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->postJson(
                '/v1/venues',
                ['name' => 'Contract Arena', 'address' => '1 Contract St', 'city' => 'Austin', 'country' => 'US', 'capacity' => 500],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues 422' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->postJson(
                '/v1/venues',
                ['name' => '', 'address' => '1 Contract St', 'city' => 'Austin', 'country' => 'US', 'capacity' => 500],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/venues 200' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->getJson('/v1/venues', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues 400' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->getJson('/v1/venues?sort=capacity', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues 401' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->getJson('/v1/venues', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/venues 403' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->getJson('/v1/venues', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue} 200' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->getJson('/v1/venues/'.$venue->id, [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue} 401' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->getJson('/v1/venues/'.$venue->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/venues/{venue} 403' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->getJson('/v1/venues/'.$venue->id, [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue} 404' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->getJson('/v1/venues/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/venues/{venue} 200' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->patchJson(
                '/v1/venues/'.$venue->id,
                ['name' => 'Renamed Contract Arena'],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/venues/{venue} 401' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->patchJson(
                '/v1/venues/'.$venue->id,
                ['name' => 'Renamed Contract Arena'],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/venues/{venue} 403' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->patchJson(
                '/v1/venues/'.$venue->id,
                ['name' => 'Renamed Contract Arena'],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/venues/{venue} 404' => function (): TestResponse {
            $tenant = contractVenueTenant();

            return test()->patchJson(
                '/v1/venues/'.Str::uuid7(),
                ['name' => 'Ghost'],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/venues/{venue} 422' => function (): TestResponse {
            $tenant = contractVenueTenant();
            $venue = contractVenue($tenant);

            return test()->patchJson(
                '/v1/venues/'.$venue->id,
                ['capacity' => 0],
                ['Authorization' => 'Bearer '.contractVenueBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues/{venue}/seat-maps 201' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->postJson(
                "/v1/venues/{$venue->id}/seat-maps",
                contractSeatMapCreatePayload(),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues/{venue}/seat-maps 401' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->postJson(
                "/v1/venues/{$venue->id}/seat-maps",
                contractSeatMapCreatePayload(),
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues/{venue}/seat-maps 403' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->postJson(
                "/v1/venues/{$venue->id}/seat-maps",
                contractSeatMapCreatePayload(),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues/{venue}/seat-maps 404' => function (): TestResponse {
            $tenant = contractSeatMapTenant();

            return test()->postJson(
                '/v1/venues/'.Str::uuid7().'/seat-maps',
                contractSeatMapCreatePayload(),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/venues/{venue}/seat-maps 422' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->postJson(
                "/v1/venues/{$venue->id}/seat-maps",
                contractSeatMapCreatePayload(['name' => '']),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/venues/{venue}/seat-maps 200' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            contractSeatMap($tenant, $venue);

            return test()->getJson("/v1/venues/{$venue->id}/seat-maps", [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue}/seat-maps 400' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->getJson("/v1/venues/{$venue->id}/seat-maps?sort=venue_id", [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue}/seat-maps 401' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->getJson("/v1/venues/{$venue->id}/seat-maps", ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/venues/{venue}/seat-maps 403' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);

            return test()->getJson("/v1/venues/{$venue->id}/seat-maps", [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/venues/{venue}/seat-maps 404' => function (): TestResponse {
            $tenant = contractSeatMapTenant();

            return test()->getJson('/v1/venues/'.Str::uuid7().'/seat-maps', [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/seat-maps/{seat_map} 200' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->getJson('/v1/seat-maps/'.$seatMap->id, [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/seat-maps/{seat_map} 401' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->getJson('/v1/seat-maps/'.$seatMap->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/seat-maps/{seat_map} 403' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->getJson('/v1/seat-maps/'.$seatMap->id, [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/seat-maps/{seat_map} 404' => function (): TestResponse {
            $tenant = contractSeatMapTenant();

            return test()->getJson('/v1/seat-maps/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'put /v1/seat-maps/{seat_map} 200' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->putJson(
                '/v1/seat-maps/'.$seatMap->id,
                contractSeatMapCreatePayload(['name' => 'Contract Replaced Bowl']),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'put /v1/seat-maps/{seat_map} 401' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->putJson(
                '/v1/seat-maps/'.$seatMap->id,
                contractSeatMapCreatePayload(),
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'put /v1/seat-maps/{seat_map} 403' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->putJson(
                '/v1/seat-maps/'.$seatMap->id,
                contractSeatMapCreatePayload(),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'put /v1/seat-maps/{seat_map} 404' => function (): TestResponse {
            $tenant = contractSeatMapTenant();

            return test()->putJson(
                '/v1/seat-maps/'.Str::uuid7(),
                contractSeatMapCreatePayload(),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'put /v1/seat-maps/{seat_map} 422' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->putJson(
                '/v1/seat-maps/'.$seatMap->id,
                contractSeatMapCreatePayload(['name' => '']),
                ['Authorization' => 'Bearer '.contractSeatMapBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'delete /v1/seat-maps/{seat_map} 401' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->deleteJson('/v1/seat-maps/'.$seatMap->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/seat-maps/{seat_map} 403' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);

            return test()->deleteJson('/v1/seat-maps/'.$seatMap->id, [], [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/seat-maps/{seat_map} 404' => function (): TestResponse {
            $tenant = contractSeatMapTenant();

            return test()->deleteJson('/v1/seat-maps/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/seat-maps/{seat_map} 409' => function (): TestResponse {
            $tenant = contractSeatMapTenant();
            $venue = contractVenue($tenant);
            $seatMap = contractSeatMap($tenant, $venue);
            app(TenantTransaction::class)->asTenant(
                $tenant->id,
                fn () => Event::factory()->atVenue($venue->id)->create([
                    'tenant_id' => $tenant->id,
                    'seat_map_id' => $seatMap->id,
                ]),
            );

            return test()->deleteJson('/v1/seat-maps/'.$seatMap->id, [], [
                'Authorization' => 'Bearer '.contractSeatMapBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events 201' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson(
                '/v1/events',
                contractEventCreatePayload(),
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events 401' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson('/v1/events', contractEventCreatePayload(), ['X-Tenant-Id' => $tenant->id]);
        },
        'post /v1/events 403' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson(
                '/v1/events',
                contractEventCreatePayload(),
                ['Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events 422' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson(
                '/v1/events',
                contractEventCreatePayload(['name' => []]),
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/events 200' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->getJson('/v1/events', [
                'Authorization' => 'Bearer '.contractEventBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events 400' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->getJson('/v1/events?sort=venue_id', [
                'Authorization' => 'Bearer '.contractEventBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events 401' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->getJson('/v1/events', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/events 403' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->getJson('/v1/events', [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event} 200' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id, [
                'Authorization' => 'Bearer '.contractEventBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event} 401' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/events/{event} 403' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id, [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event} 404' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->getJson('/v1/events/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractEventBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/events/{event} 200' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id,
                ['timezone' => 'America/Chicago'],
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event} 401' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id,
                ['timezone' => 'America/Chicago'],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event} 403' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id,
                ['timezone' => 'America/Chicago'],
                ['Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event} 404' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->patchJson(
                '/v1/events/'.Str::uuid7(),
                ['timezone' => 'UTC'],
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event} 409' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Canceled]);

            return test()->patchJson(
                '/v1/events/'.$event->id,
                ['timezone' => 'UTC'],
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event} 422' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id,
                ['timezone' => 'Not/ARealZone'],
                ['Authorization' => 'Bearer '.contractEventBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/ticket-types 201' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->postJson(
                "/v1/events/{$event->id}/ticket-types",
                contractTicketTypeCreatePayload(),
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/ticket-types 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->postJson(
                "/v1/events/{$event->id}/ticket-types",
                contractTicketTypeCreatePayload(),
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/ticket-types 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->postJson(
                "/v1/events/{$event->id}/ticket-types",
                contractTicketTypeCreatePayload(),
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/ticket-types 409' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Canceled]);

            return test()->postJson(
                "/v1/events/{$event->id}/ticket-types",
                contractTicketTypeCreatePayload(),
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/ticket-types 422' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->postJson(
                "/v1/events/{$event->id}/ticket-types",
                contractTicketTypeCreatePayload(['price' => ['amount' => -1, 'currency' => 'USD']]),
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'get /v1/events/{event}/ticket-types 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/ticket-types", [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/ticket-types 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/ticket-types", ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/events/{event}/ticket-types 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/ticket-types", [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/ticket-types 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/ticket-types', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type} 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->getJson('/v1/ticket-types/'.$ticketType->id, [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type} 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->getJson('/v1/ticket-types/'.$ticketType->id, ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/ticket-types/{ticket_type} 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->getJson('/v1/ticket-types/'.$ticketType->id, [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type} 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->getJson('/v1/ticket-types/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type}/inventory 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);
            app(TenantTransaction::class)->asTenant($tenant->id, fn () => TicketTypeInventory::factory()->create([
                'tenant_id' => $tenant->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 10,
                'sold' => 0,
                'held' => 0,
            ]));

            return test()->getJson('/v1/ticket-types/'.$ticketType->id.'/inventory', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type}/inventory 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->getJson('/v1/ticket-types/'.$ticketType->id.'/inventory', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/ticket-types/{ticket_type}/inventory 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->getJson('/v1/ticket-types/'.$ticketType->id.'/inventory', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ticket-types/{ticket_type}/inventory 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->getJson('/v1/ticket-types/'.Str::uuid7().'/inventory', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/storefront/events/{event}/seats 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $domain = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::factory()->create(['tenant_id' => $tenant->id]));
            ['event' => $event] = contractSeatedEvent($tenant);

            return test()->getJson('http://'.$domain->domain.'/v1/storefront/events/'.$event->id.'/seats');
        },
        'get /v1/storefront/events/{event}/seats 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $domain = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::factory()->create(['tenant_id' => $tenant->id]));

            return test()->getJson('http://'.$domain->domain.'/v1/storefront/events/'.Str::uuid7().'/seats');
        },
        'get /v1/storefront/events/{event}/seats 409' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $domain = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::factory()->create(['tenant_id' => $tenant->id]));
            $event = contractEvent($tenant, ['status' => EventStatus::Published]);

            return test()->getJson('http://'.$domain->domain.'/v1/storefront/events/'.$event->id.'/seats');
        },
        'get /v1/events/{event}/seats 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event] = contractSeatedEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/seats', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/seats 400' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event] = contractSeatedEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/seats?filter[unknown]=x', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/seats 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event] = contractSeatedEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/seats', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/events/{event}/seats 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event] = contractSeatedEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/seats', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/seats 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/seats', [
                'Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/events/{event}/seats 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event, 'eventSeatId' => $eventSeatId] = contractSeatedEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id.'/seats',
                ['operations' => [['event_seat_id' => $eventSeatId, 'op' => 'block']]],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event}/seats 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event, 'eventSeatId' => $eventSeatId] = contractSeatedEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id.'/seats',
                ['operations' => [['event_seat_id' => $eventSeatId, 'op' => 'block']]],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event}/seats 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event, 'eventSeatId' => $eventSeatId] = contractSeatedEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id.'/seats',
                ['operations' => [['event_seat_id' => $eventSeatId, 'op' => 'block']]],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event}/seats 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->patchJson(
                '/v1/events/'.Str::uuid7().'/seats',
                ['operations' => [['event_seat_id' => Str::uuid7()->toString(), 'op' => 'block']]],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event}/seats 409' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event, 'eventSeatId' => $eventSeatId] = contractSeatedEvent($tenant);
            app(TenantTransaction::class)->asTenant($tenant->id, fn () => EventSeat::query()->whereKey($eventSeatId)->update(['status' => EventSeatStatus::Held->value]));

            return test()->patchJson(
                '/v1/events/'.$event->id.'/seats',
                ['operations' => [['event_seat_id' => $eventSeatId, 'op' => 'block']]],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/events/{event}/seats 422' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            ['event' => $event, 'eventSeatId' => $eventSeatId] = contractSeatedEvent($tenant);

            return test()->patchJson(
                '/v1/events/'.$event->id.'/seats',
                ['operations' => [['event_seat_id' => $eventSeatId, 'op' => 'melt']]],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.manage_seating']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 200' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->patchJson(
                '/v1/ticket-types/'.$ticketType->id,
                ['name' => 'Contract Renamed'],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 401' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->patchJson(
                '/v1/ticket-types/'.$ticketType->id,
                ['name' => 'Contract Renamed'],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 403' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->patchJson(
                '/v1/ticket-types/'.$ticketType->id,
                ['name' => 'Contract Renamed'],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 404' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();

            return test()->patchJson(
                '/v1/ticket-types/'.Str::uuid7(),
                ['name' => 'Contract Renamed'],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 409' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Canceled]);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->patchJson(
                '/v1/ticket-types/'.$ticketType->id,
                ['name' => 'Contract Renamed'],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/ticket-types/{ticket_type} 422' => function (): TestResponse {
            $tenant = contractTicketTypeTenant();
            $event = contractEvent($tenant);
            $ticketType = contractTicketType($tenant, $event->id);

            return test()->patchJson(
                '/v1/ticket-types/'.$ticketType->id,
                ['price' => ['amount' => 5000, 'currency' => 'EUR']],
                ['Authorization' => 'Bearer '.contractTicketTypeBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'post /v1/events/{event}/media 201' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->post(
                "/v1/events/{$event->id}/media",
                ['file' => UploadedFile::fake()->image('contract-cover.jpg'), 'collection' => 'cover'],
                [
                    'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                    'X-Tenant-Id' => $tenant->id,
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/events/{event}/media 401' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->post(
                "/v1/events/{$event->id}/media",
                ['file' => UploadedFile::fake()->image('contract-cover.jpg'), 'collection' => 'cover'],
                ['X-Tenant-Id' => $tenant->id, 'Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
            );
        },
        'post /v1/events/{event}/media 403' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->post(
                "/v1/events/{$event->id}/media",
                ['file' => UploadedFile::fake()->image('contract-cover.jpg'), 'collection' => 'cover'],
                [
                    'Authorization' => 'Bearer '.contractEventMediaBearer($tenant, ['events.view']),
                    'X-Tenant-Id' => $tenant->id,
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/events/{event}/media 404' => function (): TestResponse {
            $tenant = contractEventMediaTenant();

            return test()->post(
                '/v1/events/'.Str::uuid7().'/media',
                ['file' => UploadedFile::fake()->image('contract-cover.jpg'), 'collection' => 'cover'],
                [
                    'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                    'X-Tenant-Id' => $tenant->id,
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'post /v1/events/{event}/media 413' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);
            $token = contractEventMediaBearer($tenant);

            return test()->call(
                'POST',
                "/v1/events/{$event->id}/media",
                ['collection' => 'cover'],
                [],
                ['file' => UploadedFile::fake()->image('contract-cover.jpg')],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X_TENANT_ID' => $tenant->id,
                    'CONTENT_TYPE' => 'multipart/form-data',
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
                ],
            );
        },
        'post /v1/events/{event}/media 422' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->post(
                "/v1/events/{$event->id}/media",
                ['file' => UploadedFile::fake()->image('contract-cover.jpg'), 'collection' => 'banner'],
                [
                    'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                    'X-Tenant-Id' => $tenant->id,
                    'Content-Type' => 'multipart/form-data',
                    'Accept' => 'application/json',
                ],
            );
        },
        'get /v1/events/{event}/media 200' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);
            contractEventCoverMedia($tenant, $event->id);

            return test()->getJson("/v1/events/{$event->id}/media", [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/media 401' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/media", ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/events/{event}/media 403' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/media", [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant, ['events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/media 404' => function (): TestResponse {
            $tenant = contractEventMediaTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/media', [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/media 422' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);

            return test()->getJson("/v1/events/{$event->id}/media?collection=banner", [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/media/{media} 401' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);
            $media = contractEventCoverMedia($tenant, $event->id);

            return test()->deleteJson('/v1/media/'.$media->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/media/{media} 403' => function (): TestResponse {
            $tenant = contractEventMediaTenant();
            $event = contractEvent($tenant);
            $media = contractEventCoverMedia($tenant, $event->id);

            return test()->deleteJson('/v1/media/'.$media->id, [], [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/media/{media} 404' => function (): TestResponse {
            $tenant = contractEventMediaTenant();

            return test()->deleteJson('/v1/media/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractEventMediaBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/storefront/events 200' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            contractEvent($tenant, ['status' => EventStatus::Published]);

            return test()->getJson('http://'.$host.'/v1/storefront/events');
        },
        'get /v1/storefront/events 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/events?q=a');
        },
        'get /v1/storefront/events/{event} 200' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Published]);

            return test()->getJson('http://'.$host.'/v1/storefront/events/'.$event->id);
        },
        'get /v1/storefront/events/{event} 404' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->getJson('http://'.$host.'/v1/storefront/events/'.$event->id);
        },
        'post /v1/events/{event}/publish 200' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->postJson('/v1/events/'.$event->id.'/publish', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/publish 401' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->postJson('/v1/events/'.$event->id.'/publish', [], ['X-Tenant-Id' => $tenant->id]);
        },
        'post /v1/events/{event}/publish 403' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->postJson('/v1/events/'.$event->id.'/publish', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/publish 404' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson('/v1/events/'.Str::uuid7().'/publish', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/publish 409' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Published]);

            return test()->postJson('/v1/events/'.$event->id.'/publish', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/cancel 200' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Published]);

            return test()->postJson('/v1/events/'.$event->id.'/cancel', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/cancel 401' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->postJson('/v1/events/'.$event->id.'/cancel', [], ['X-Tenant-Id' => $tenant->id]);
        },
        'post /v1/events/{event}/cancel 403' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Draft]);

            return test()->postJson('/v1/events/'.$event->id.'/cancel', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/cancel 404' => function (): TestResponse {
            $tenant = contractEventTenant();

            return test()->postJson('/v1/events/'.Str::uuid7().'/cancel', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/cancel 409' => function (): TestResponse {
            $tenant = contractEventTenant();
            $event = contractEvent($tenant, ['status' => EventStatus::Canceled]);

            return test()->postJson('/v1/events/'.$event->id.'/cancel', [], [
                'Authorization' => 'Bearer '.contractEventBearer($tenant, ['events.view', 'events.manage', 'events.publish']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/capabilities 200' => fn (): TestResponse => test()->getJson('/v1/capabilities', [
            'Authorization' => 'Bearer '.contractStaffBearer(),
        ]),
        'get /v1/capabilities 401' => fn (): TestResponse => test()->getJson('/v1/capabilities'),
        'get /v1/memberships 200' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships', [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/memberships 400' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships?sort=email', [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/memberships 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->getJson('/v1/memberships', ['X-Tenant-Id' => $tenant->id]);
        },
        'get /v1/memberships 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $stranger = User::factory()->create();

            $response = test()->postJson('/v1/auth/staff/token', [
                'email' => $stranger->email,
                'password' => 'password',
            ]);

            return test()->getJson('/v1/memberships', [
                'Authorization' => 'Bearer '.$response->json('access_token'),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 201' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            Mail::fake();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-post-'.Str::uuid7().'@example.com',
                'name' => 'Contract Post Invitee',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-401@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], ['X-Tenant-Id' => $tenant->id]);
        },
        'post /v1/memberships 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-403@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-404@example.com',
                'name' => 'X',
                'role_id' => (string) Str::uuid7(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $existing = User::factory()->create(['email' => 'contract-membership-exists@example.com']);
            contractMembership($tenant, ['user_id' => $existing->id]);

            return test()->postJson('/v1/memberships', [
                'email' => 'contract-membership-exists@example.com',
                'name' => 'X',
                'role_id' => contractTemplateRoleId(),
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/memberships 422' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->postJson('/v1/memberships', [
                'email' => 'not-an-email',
                'name' => '',
                'role_id' => 'not-a-uuid',
            ], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/memberships/{membership} 200' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);
            $newRoleId = contractRole($tenant)->id;

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => $newRoleId],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => contractTemplateRoleId()],
                ['X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => contractTemplateRoleId()],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->patchJson(
                '/v1/memberships/'.Str::uuid7(),
                ['role_id' => contractTemplateRoleId()],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $onlyOwner = contractMembership($tenant, ['role_id' => contractTemplateRoleId()]);
            $otherRoleId = contractRole($tenant)->id;

            return test()->patchJson(
                '/v1/memberships/'.$onlyOwner->id,
                ['role_id' => $otherRoleId],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'patch /v1/memberships/{membership} 422' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->patchJson(
                '/v1/memberships/'.$membership->id,
                ['role_id' => 'not-a-uuid'],
                ['Authorization' => 'Bearer '.contractMembershipBearer($tenant), 'X-Tenant-Id' => $tenant->id],
            );
        },
        'delete /v1/memberships/{membership} 401' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->deleteJson('/v1/memberships/'.$membership->id, [], ['X-Tenant-Id' => $tenant->id]);
        },
        'delete /v1/memberships/{membership} 403' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $membership = contractMembership($tenant);

            return test()->deleteJson('/v1/memberships/'.$membership->id, [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/memberships/{membership} 404' => function (): TestResponse {
            $tenant = contractMembershipTenant();

            return test()->deleteJson('/v1/memberships/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/memberships/{membership} 409' => function (): TestResponse {
            $tenant = contractMembershipTenant();
            $onlyOwner = contractMembership($tenant, ['role_id' => contractTemplateRoleId()]);

            return test()->deleteJson('/v1/memberships/'.$onlyOwner->id, [], [
                'Authorization' => 'Bearer '.contractMembershipBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // The 204 documents no content, so it has no coverage key here
        // (mirroring the precedent above at 'get
        // /v1/internal/domain-verification'); the feature test
        // (tests/Feature/Identity/InvitationAcceptanceTest.php)
        // conformance-asserts it.
        'post /v1/auth/staff/invitation/accept 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/invitation/accept', [
            'token' => 'not-a-real-token',
            'password' => 'a-real-password',
        ]),
        'post /v1/auth/staff/invitation/accept 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/invitation/accept', [
            'token' => '',
            'password' => 'short',
        ]),
        // The 202 and 204 document no content, so they have no coverage
        // keys here (mirroring the precedent above at 'post
        // /v1/auth/staff/invitation/accept 204'); the feature test
        // (tests/Feature/Identity/StaffPasswordResetTest.php)
        // conformance-asserts both.
        'post /v1/auth/staff/password/reset 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/password/reset', [
            'email' => 'not-an-email',
        ]),
        'post /v1/auth/staff/password/reset/confirm 401' => fn (): TestResponse => test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => 'not-a-real-token',
            'password' => 'a-real-password',
        ]),
        'post /v1/auth/staff/password/reset/confirm 422' => fn (): TestResponse => test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => '',
            'password' => 'short',
        ]),
        // Task breakdown item 13: the customer authentication and
        // lifecycle surface. Every case below hits the real Host-resolved
        // endpoint rather than X-Tenant-Id.
        'post /v1/auth/customer/token 200' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            contractCustomer($tenant, ['email' => 'contract-customer-token@example.com', 'password' => 'password']);

            return test()->postJson('http://'.$host.'/v1/auth/customer/token', [
                'email' => 'contract-customer-token@example.com',
                'password' => 'password',
            ]);
        },
        'post /v1/auth/customer/token 401' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/token', [
                'email' => 'unknown@example.com',
                'password' => 'password',
            ]);
        },
        'post /v1/auth/customer/token 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/token', ['email' => 'not-an-email', 'password' => 'x']);
        },
        'post /v1/auth/customer/refresh 200' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            contractCustomer($tenant, ['email' => 'contract-customer-refresh@example.com', 'password' => 'password']);

            $pair = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
                'email' => 'contract-customer-refresh@example.com',
                'password' => 'password',
            ])->json();

            return test()->postJson('http://'.$host.'/v1/auth/customer/refresh', ['refresh_token' => $pair['refresh_token']]);
        },
        'post /v1/auth/customer/refresh 401' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/refresh', ['refresh_token' => 'not-a-real-token']);
        },
        'post /v1/auth/customer/refresh 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/refresh', ['refresh_token' => '']);
        },
        // The 204 documents no content, so it has no coverage key here
        // (mirroring the precedent above at 'post
        // /v1/auth/staff/invitation/accept 204'); the feature test
        // (tests/Feature/Identity/CustomerAuthenticationTest.php)
        // conformance-asserts it.
        'post /v1/auth/customer/logout 401' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/logout');
        },
        'post /v1/customers 201' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/customers', [
                'email' => 'contract-new-customer@example.com',
                'name' => 'Contract Customer',
            ]);
        },
        'post /v1/customers 409' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            contractCustomer($tenant, ['email' => 'contract-taken@example.com']);

            return test()->postJson('http://'.$host.'/v1/customers', [
                'email' => 'contract-taken@example.com',
                'name' => 'Contract Customer',
            ]);
        },
        'post /v1/customers 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/customers', ['email' => 'not-an-email', 'name' => 'x']);
        },
        'post /v1/auth/customer/claim 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/claim', ['email' => 'not-an-email']);
        },
        'post /v1/auth/customer/claim/confirm 401' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
                'token' => 'not-a-real-token',
                'password' => 'a-real-password',
            ]);
        },
        'post /v1/auth/customer/claim/confirm 409' => function (): TestResponse {
            [$tenant, $host] = contractCustomerTenant();
            $customer = contractCustomer($tenant, ['password' => 'already-claimed-password']);
            $token = ClaimToken::issue($customer->id);

            return test()->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
                'token' => $token,
                'password' => 'a-real-password',
            ]);
        },
        'post /v1/auth/customer/claim/confirm 422' => function (): TestResponse {
            [, $host] = contractCustomerTenant();

            return test()->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', ['token' => '', 'password' => '']);
        },
        'get /v1/storefront/events/{event}/availability 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event] = contractHoldFixture($tenant);

            return test()->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability');
        },
        'get /v1/storefront/events/{event}/availability 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/availability');
        },
        'post /v1/storefront/holds 201' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);

            return test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]);
        },
        'post /v1/storefront/holds 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => (string) Str::uuid7(),
                'items' => [['ticket_type_id' => (string) Str::uuid7(), 'quantity' => 1]],
            ]);
        },
        'post /v1/storefront/holds 422' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event] = contractHoldFixture($tenant);
            ['ticketType' => $foreignTicketType] = contractHoldFixture($tenant);

            return test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $foreignTicketType->id, 'quantity' => 1]],
            ]);
        },
        'post /v1/storefront/holds 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant, quantity: 1);

            return test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]);
        },
        // Stage-10 plan, TDD sequencing Slice 6, task breakdown item 9:
        // a flagged event with no X-Admission-Token header (code
        // admission_required); admission_invalid is the same
        // HoldAdmissionProblem oneOf branch, already covered at the
        // Feature level (tests/Feature/Inventory/HoldEndpointsTest.php).
        'post /v1/storefront/holds 403' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant, eventAttributes: [
                'on_sale_policy' => new OnSalePolicyData(highDemand: true),
            ]);

            return test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]);
        },
        'get /v1/storefront/holds/{hold} 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);

            $created = test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ])->json();

            return test()->getJson('http://'.$host.'/v1/storefront/holds/'.$created['id']);
        },
        'get /v1/storefront/holds/{hold} 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/holds/'.Str::uuid7());
        },
        // No exerciser for the 204 response: it carries no content, so
        // OpenApiSpec::documentedResponseSchemas() (content-keyed) never
        // lists it as a documented response triple in the first place,
        // mirroring every other 204 DELETE in this suite (e.g. seat maps,
        // roles, memberships). HoldEndpointsTest's own DELETE describe
        // block covers the 204 shape instead.
        'delete /v1/storefront/holds/{hold} 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->deleteJson('http://'.$host.'/v1/storefront/holds/'.Str::uuid7());
        },
        'delete /v1/storefront/holds/{hold} 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);

            $created = test()->postJson('http://'.$host.'/v1/storefront/holds', [
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ])->json();

            app(TenantTransaction::class)->asTenant($tenant->id, function () use ($created): void {
                DB::table('holds')->where('id', $created['id'])->update(['status' => 'committed']);
            });

            return test()->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id']);
        },
        // Stage-10 plan, TDD sequencing Slice 4, task breakdown item 7:
        // the waiting-room join and poll surface.
        'post /v1/storefront/events/{event}/queue-entries 201' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $event = contractQueueEvent($tenant);

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', []);
        },
        'post /v1/storefront/events/{event}/queue-entries 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/queue-entries', []);
        },
        'post /v1/storefront/events/{event}/queue-entries 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event] = contractHoldFixture($tenant);

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', []);
        },
        'post /v1/storefront/events/{event}/queue-entries 422' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $event = contractQueueEvent($tenant);

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [
                'challenge_response' => 42,
            ]);
        },
        'post /v1/storefront/events/{event}/queue-entries 403' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $event = contractQueueEvent($tenant, challengeRequired: true);

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', []);
        },
        'get /v1/storefront/queue-entries/{entry} 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $event = contractQueueEvent($tenant);

            $created = test()->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])->json();

            return test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$created['id']);
        },
        'get /v1/storefront/queue-entries/{entry} 404' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.Str::uuid7());
        },
        // The queue_entry and queue_poll tiers, driven past their own limit
        // through a config override rather than the platform default, so the
        // documented 429 shape is asserted without issuing 20-plus requests
        // (stage-10 plan, Endpoints "Rate limiting tiers").
        'post /v1/storefront/events/{event}/queue-entries 429' => function (): TestResponse {
            config(['onsale.rate_limits.queue_entry' => ['max_attempts' => 1, 'decay_seconds' => 60]]);
            ['host' => $host] = contractHoldTenant();

            test()->postJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/queue-entries', []);

            return test()->postJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/queue-entries', []);
        },
        'get /v1/storefront/queue-entries/{entry} 429' => function (): TestResponse {
            config(['onsale.rate_limits.queue_poll' => ['max_attempts' => 1, 'decay_seconds' => 60]]);
            ['host' => $host] = contractHoldTenant();

            test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.Str::uuid7());

            return test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.Str::uuid7());
        },
        // Stage-07 plan, task breakdown item 3: the storefront order
        // conversion surface. Every case authenticates as a customer over
        // the Host-resolved endpoint; only the 401 goes without a bearer.
        'post /v1/storefront/orders 201' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/storefront/orders 401' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => (string) Str::uuid7(),
            ]);
        },
        'post /v1/storefront/orders 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => (string) Str::uuid7(),
            ], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/storefront/orders 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);
            $holdId = contractOrderHold($host, $event->id, $ticketType->id);

            test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => $holdId,
            ], ['Authorization' => 'Bearer '.$token]);

            return test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => $holdId,
            ], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/storefront/orders 422' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/orders', [], [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        // Stage-07 plan, task breakdown item 10: the staff customer lookup.
        'get /v1/customers 200' => function (): TestResponse {
            $tenant = contractTenant();
            contractCustomer($tenant, ['email' => 'contract-lookup@example.com', 'name' => 'Look Up']);

            return test()->getJson('/v1/customers?filter[email]=contract-lookup@example.com', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/customers 400' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/customers?filter[phone]=555', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/customers 401' => fn (): TestResponse => test()->getJson('/v1/customers'),
        'get /v1/customers 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/customers', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // Stage-12 plan, Endpoints "POST /v1/customers/{customer}/data-
        // subject-requests": erasure only until Slice 2 (task 6) lands
        // export; contractVenueBearer's own generic-capabilities
        // parameter is reused here the same way the customers.view
        // exercisers above reuse it.
        'post /v1/customers/{customer}/data-subject-requests 201' => function (): TestResponse {
            $tenant = contractTenant();
            $customer = contractCustomer($tenant, ['password' => 'password']);

            return test()->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', [
                'type' => 'erasure',
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.erase']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/customers/{customer}/data-subject-requests 401' => function (): TestResponse {
            $tenant = contractTenant();
            $customer = contractCustomer($tenant);

            return test()->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure']);
        },
        'post /v1/customers/{customer}/data-subject-requests 403' => function (): TestResponse {
            $tenant = contractTenant();
            $customer = contractCustomer($tenant);

            return test()->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', [
                'type' => 'erasure',
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/customers/{customer}/data-subject-requests 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/customers/'.Str::uuid7().'/data-subject-requests', [
                'type' => 'erasure',
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.erase']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/customers/{customer}/data-subject-requests 409' => function (): TestResponse {
            $tenant = contractTenant();
            $customer = contractCustomer($tenant, ['anonymized_at' => now()]);

            return test()->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', [
                'type' => 'erasure',
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.erase']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/customers/{customer}/data-subject-requests 422' => function (): TestResponse {
            $tenant = contractTenant();
            $customer = contractCustomer($tenant);

            return test()->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', [
                'type' => 'export',
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['customers.erase']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/storefront/orders/{order} 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token])->json('id');

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.$orderId, [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'get /v1/storefront/orders/{order} 401' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7());
        },
        'get /v1/storefront/orders/{order} 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'get /v1/storefront/orders/{order}/tickets 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token])->json('id');

            app(TenantTransaction::class)->asTenant($tenant->id, function () use ($orderId): void {
                app(MarkOrderAwaitingPayment::class)($orderId);
                app(MarkOrderPaid::class)($orderId);
            });

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/tickets', [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'get /v1/storefront/orders/{order}/tickets 401' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/tickets');
        },
        'get /v1/storefront/orders/{order}/tickets 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/tickets', [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        // Stage-07 plan, task breakdown items 13 and 14: staff order surface.
        'get /v1/orders 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            contractStaffOrder($tenant, $host);

            return test()->getJson('/v1/orders', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/orders 400' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/orders?filter[nope]=1', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/orders 401' => fn (): TestResponse => test()->getJson('/v1/orders'),
        'get /v1/orders 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/orders', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/orders/{order} 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $orderId = contractStaffOrder($tenant, $host);

            return test()->getJson('/v1/orders/'.$orderId, [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/orders/{order} 401' => fn (): TestResponse => test()->getJson('/v1/orders/'.Str::uuid7()),
        'get /v1/orders/{order} 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/orders/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/orders/{order} 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/orders/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/orders/{order}/resend-tickets 401' => fn (): TestResponse => test()->postJson('/v1/orders/'.Str::uuid7().'/resend-tickets'),
        'post /v1/orders/{order}/resend-tickets 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/orders/'.Str::uuid7().'/resend-tickets', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/orders/{order}/resend-tickets 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/orders/'.Str::uuid7().'/resend-tickets', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.resend_tickets']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/orders/{order}/resend-tickets 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $orderId = contractStaffOrder($tenant, $host);

            return test()->postJson('/v1/orders/'.$orderId.'/resend-tickets', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.resend_tickets']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // Stage-07 plan, task breakdown item 12: promo code admin CRUD.
        'get /v1/promo-codes 200' => function (): TestResponse {
            $tenant = contractTenant();
            contractPromoCode($tenant);

            return test()->getJson('/v1/promo-codes', contractPromoHeaders($tenant));
        },
        'get /v1/promo-codes 400' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/promo-codes?filter[nope]=1', contractPromoHeaders($tenant));
        },
        'get /v1/promo-codes 401' => fn (): TestResponse => test()->getJson('/v1/promo-codes'),
        'get /v1/promo-codes 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/promo-codes', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/promo-codes 201' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/promo-codes', [
                'code' => 'CONTRACTNEW',
                'discount_type' => 'percentage',
                'discount_value' => 1000,
            ], contractPromoHeaders($tenant));
        },
        'post /v1/promo-codes 401' => fn (): TestResponse => test()->postJson('/v1/promo-codes', [
            'code' => 'X',
            'discount_type' => 'percentage',
            'discount_value' => 1,
        ]),
        'post /v1/promo-codes 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/promo-codes', [
                'code' => 'X',
                'discount_type' => 'percentage',
                'discount_value' => 1,
            ], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/promo-codes 422' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/promo-codes', [], contractPromoHeaders($tenant));
        },
        'get /v1/promo-codes/{promo_code} 200' => function (): TestResponse {
            $tenant = contractTenant();
            $promo = contractPromoCode($tenant);

            return test()->getJson('/v1/promo-codes/'.$promo->id, contractPromoHeaders($tenant));
        },
        'get /v1/promo-codes/{promo_code} 401' => fn (): TestResponse => test()->getJson('/v1/promo-codes/'.Str::uuid7()),
        'get /v1/promo-codes/{promo_code} 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/promo-codes/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/promo-codes/{promo_code} 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/promo-codes/'.Str::uuid7(), contractPromoHeaders($tenant));
        },
        'patch /v1/promo-codes/{promo_code} 200' => function (): TestResponse {
            $tenant = contractTenant();
            $promo = contractPromoCode($tenant);

            return test()->patchJson('/v1/promo-codes/'.$promo->id, [
                'usage_limit' => 5,
            ], contractPromoHeaders($tenant));
        },
        'patch /v1/promo-codes/{promo_code} 401' => fn (): TestResponse => test()->patchJson('/v1/promo-codes/'.Str::uuid7(), []),
        'patch /v1/promo-codes/{promo_code} 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->patchJson('/v1/promo-codes/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'patch /v1/promo-codes/{promo_code} 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->patchJson('/v1/promo-codes/'.Str::uuid7(), [], contractPromoHeaders($tenant));
        },
        'patch /v1/promo-codes/{promo_code} 422' => function (): TestResponse {
            $tenant = contractTenant();
            $promo = contractPromoCode($tenant, ['usage_count' => 1]);

            return test()->patchJson('/v1/promo-codes/'.$promo->id, [
                'code' => 'RENAMED',
            ], contractPromoHeaders($tenant));
        },
        'post /v1/storefront/promo-codes/check 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): void {
                PromoCode::factory()->create([
                    'tenant_id' => $tenant->id,
                    'code' => 'CONTRACT10',
                ]);
            });

            return test()->postJson('http://'.$host.'/v1/storefront/promo-codes/check', [
                'code' => 'CONTRACT10',
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/storefront/promo-codes/check 401' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/promo-codes/check', [
                'code' => 'CONTRACT10',
                'hold_id' => (string) Str::uuid7(),
            ]);
        },
        'post /v1/storefront/promo-codes/check 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/promo-codes/check', [
                'code' => 'CONTRACT10',
                'hold_id' => (string) Str::uuid7(),
            ], ['Authorization' => 'Bearer '.$token]);
        },
        'post /v1/storefront/promo-codes/check 422' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/promo-codes/check', [], [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'post /v1/storefront/orders/{order}/cancel 200' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token])->json('id');

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/cancel', [], [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'post /v1/storefront/orders/{order}/cancel 401' => function (): TestResponse {
            ['host' => $host] = contractHoldTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/cancel');
        },
        'post /v1/storefront/orders/{order}/cancel 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/cancel', [], [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'post /v1/storefront/orders/{order}/cancel 409' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
            $token = contractOrderCustomerBearer($tenant, $host);

            $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
                'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
            ], ['Authorization' => 'Bearer '.$token])->json('id');

            test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/cancel', [], [
                'Authorization' => 'Bearer '.$token,
            ]);

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/cancel', [], [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'get /v1/storefront/orders/{order}/payment-methods 200' => function (): TestResponse {
            $order = contractPaymentOrder();

            return test()->getJson('http://'.$order['host'].'/v1/storefront/orders/'.$order['orderId'].'/payment-methods', [
                'Authorization' => 'Bearer '.$order['token'],
            ]);
        },
        'get /v1/storefront/orders/{order}/payment-methods 401' => function (): TestResponse {
            ['host' => $host] = contractPaymentTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/payment-methods');
        },
        'get /v1/storefront/orders/{order}/payment-methods 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractPaymentTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->getJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/payment-methods', [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'get /v1/storefront/orders/{order}/payment-methods 409' => function (): TestResponse {
            $order = contractPaymentOrder();

            app(TenantTransaction::class)->asTenant(
                $order['tenant']->id,
                fn () => app(MarkOrderAwaitingPayment::class)($order['orderId']),
            );

            return test()->getJson('http://'.$order['host'].'/v1/storefront/orders/'.$order['orderId'].'/payment-methods', [
                'Authorization' => 'Bearer '.$order['token'],
            ]);
        },
        'post /v1/storefront/orders/{order}/payments 201' => function (): TestResponse {
            $order = contractPaymentOrder();

            return contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_approve']], (string) Str::uuid7());
        },
        'post /v1/storefront/orders/{order}/payments 200' => function (): TestResponse {
            $order = contractPaymentOrder();
            $key = (string) Str::uuid7();
            $body = ['method' => 'card', 'details' => ['token' => 'tok_approve']];

            contractInitiatePayment($order, $body, $key);

            return contractInitiatePayment($order, $body, $key);
        },
        'post /v1/storefront/orders/{order}/payments 400' => function (): TestResponse {
            $order = contractPaymentOrder();

            return contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_approve']], null);
        },
        'post /v1/storefront/orders/{order}/payments 401' => function (): TestResponse {
            ['host' => $host] = contractPaymentTenant();

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/payments', [
                'method' => 'card',
            ], ['Idempotency-Key' => (string) Str::uuid7()]);
        },
        'post /v1/storefront/orders/{order}/payments 402' => function (): TestResponse {
            $order = contractPaymentOrder();

            return contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_decline']], (string) Str::uuid7());
        },
        'post /v1/storefront/orders/{order}/payments 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractPaymentTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->postJson('http://'.$host.'/v1/storefront/orders/'.Str::uuid7().'/payments', [
                'method' => 'card',
            ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()]);
        },
        'post /v1/storefront/orders/{order}/payments 409' => function (): TestResponse {
            $order = contractPaymentOrder();
            $key = (string) Str::uuid7();

            contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_approve']], $key);

            return contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_decline']], $key);
        },
        'post /v1/storefront/orders/{order}/payments 422' => function (): TestResponse {
            $order = contractPaymentOrder();

            return contractInitiatePayment($order, ['method' => 'crypto'], (string) Str::uuid7());
        },
        'post /v1/storefront/orders/{order}/payments 503' => function (): TestResponse {
            $order = contractPaymentOrder();

            app(FakeGatewayScenarios::class)->failNextCreate();

            return contractInitiatePayment($order, ['method' => 'card', 'details' => ['token' => 'tok_approve']], (string) Str::uuid7());
        },
        'get /v1/storefront/payments/{payment} 200' => function (): TestResponse {
            $order = contractPaymentOrder();

            $paymentId = contractInitiatePayment($order, ['method' => 'pix'], (string) Str::uuid7())->json('id');

            return test()->getJson('http://'.$order['host'].'/v1/storefront/payments/'.$paymentId, [
                'Authorization' => 'Bearer '.$order['token'],
            ]);
        },
        'get /v1/storefront/payments/{payment} 401' => function (): TestResponse {
            ['host' => $host] = contractPaymentTenant();

            return test()->getJson('http://'.$host.'/v1/storefront/payments/'.Str::uuid7());
        },
        'get /v1/storefront/payments/{payment} 404' => function (): TestResponse {
            ['tenant' => $tenant, 'host' => $host] = contractPaymentTenant();
            $token = contractOrderCustomerBearer($tenant, $host);

            return test()->getJson('http://'.$host.'/v1/storefront/payments/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.$token,
            ]);
        },
        'post /v1/payments/{payment}/refunds 201' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            return contractCreateRefund($fixture, [], (string) Str::uuid7());
        },
        'post /v1/payments/{payment}/refunds 200' => function (): TestResponse {
            $fixture = contractConfirmedPayment();
            $key = (string) Str::uuid7();

            contractCreateRefund($fixture, ['amount' => ['amount' => 100, 'currency' => 'USD']], $key);

            return contractCreateRefund($fixture, ['amount' => ['amount' => 100, 'currency' => 'USD']], $key);
        },
        'post /v1/payments/{payment}/refunds 400' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            return contractCreateRefund($fixture, [], null);
        },
        'post /v1/payments/{payment}/refunds 401' => function (): TestResponse {
            return test()->postJson('/v1/payments/'.Str::uuid7().'/refunds', []);
        },
        'post /v1/payments/{payment}/refunds 403' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            return test()->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($fixture['tenant'], ['orders.view']),
                'X-Tenant-Id' => $fixture['tenant']->id,
                'Idempotency-Key' => (string) Str::uuid7(),
            ]);
        },
        'post /v1/payments/{payment}/refunds 404' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            return test()->postJson('/v1/payments/'.Str::uuid7().'/refunds', [], [
                'Authorization' => 'Bearer '.$fixture['bearer'],
                'X-Tenant-Id' => $fixture['tenant']->id,
                'Idempotency-Key' => (string) Str::uuid7(),
            ]);
        },
        'post /v1/payments/{payment}/refunds 409' => function (): TestResponse {
            $order = contractPaymentOrder();

            $pendingPaymentId = contractInitiatePayment($order, ['method' => 'pix'], (string) Str::uuid7())->json('id');

            return test()->postJson('/v1/payments/'.$pendingPaymentId.'/refunds', [], [
                'Authorization' => 'Bearer '.contractRefundBearer($order['tenant']),
                'X-Tenant-Id' => $order['tenant']->id,
                'Idempotency-Key' => (string) Str::uuid7(),
            ]);
        },
        'post /v1/payments/{payment}/refunds 422' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            return contractCreateRefund($fixture, ['amount' => ['amount' => 100, 'currency' => 'BRL']], (string) Str::uuid7());
        },
        'get /v1/refunds 200' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            contractCreateRefund($fixture, ['amount' => ['amount' => 100, 'currency' => 'USD']], (string) Str::uuid7());

            Auth::forgetGuards();

            return test()->getJson('/v1/refunds', [
                'Authorization' => 'Bearer '.contractVenueBearer($fixture['tenant'], ['orders.view']),
                'X-Tenant-Id' => $fixture['tenant']->id,
            ]);
        },
        'get /v1/refunds 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/refunds?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/refunds 401' => function (): TestResponse {
            return test()->getJson('/v1/refunds');
        },
        'get /v1/refunds 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/refunds', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/refunds/{refund} 200' => function (): TestResponse {
            $fixture = contractConfirmedPayment();

            $refundId = contractCreateRefund($fixture, ['amount' => ['amount' => 100, 'currency' => 'USD']], (string) Str::uuid7())->json('id');

            Auth::forgetGuards();

            return test()->getJson('/v1/refunds/'.$refundId, [
                'Authorization' => 'Bearer '.contractVenueBearer($fixture['tenant'], ['orders.view']),
                'X-Tenant-Id' => $fixture['tenant']->id,
            ]);
        },
        'get /v1/refunds/{refund} 401' => function (): TestResponse {
            return test()->getJson('/v1/refunds/'.Str::uuid7());
        },
        'get /v1/refunds/{refund} 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/refunds/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/refunds/{refund} 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/refunds/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ledger-entries 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            contractSeedLedgerEntry($tenant);

            return test()->getJson('/v1/ledger-entries', [
                'Authorization' => 'Bearer '.contractLedgerBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ledger-entries 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/ledger-entries?sort=amount', [
                'Authorization' => 'Bearer '.contractLedgerBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ledger-entries 401' => function (): TestResponse {
            return test()->getJson('/v1/ledger-entries');
        },
        'get /v1/ledger-entries 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/ledger-entries', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ledger-balances 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            contractSeedLedgerEntry($tenant);

            return test()->getJson('/v1/ledger-balances', [
                'Authorization' => 'Bearer '.contractLedgerBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/ledger-balances 401' => function (): TestResponse {
            return test()->getJson('/v1/ledger-balances');
        },
        'get /v1/ledger-balances 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/ledger-balances', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/webhooks/{gateway} 401' => function (): TestResponse {
            $delivery = app(FakeGateway::class)->confirmationWebhook('fake_contract_ref', Money::of(125, 'USD'));

            return contractPostWebhook('fake', $delivery->body, 'bogus');
        },
        'post /v1/webhooks/{gateway} 404' => function (): TestResponse {
            $delivery = app(FakeGateway::class)->confirmationWebhook('fake_contract_ref', Money::of(125, 'USD'));

            return contractPostWebhook('stripe', $delivery->body, $delivery->headers['X-Fake-Signature']);
        },
        'post /v1/webhooks/{gateway} 422' => function (): TestResponse {
            $body = (string) json_encode(['type' => 'payment.confirmed']);

            return contractPostWebhook('fake', $body, hash_hmac('sha256', $body, config()->string('payments.gateways.fake.webhook_secret')));
        },
        'post /v1/submerchant-accounts 201' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
                'Authorization' => 'Bearer '.contractPayoutsManageBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts 401' => function (): TestResponse {
            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake']);
        },
        'post /v1/submerchant-accounts 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['payouts.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts 409' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsManageBearer($tenant);

            contractSubmerchantAccount($tenant, 'fake');

            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts 422' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'nope'], [
                'Authorization' => 'Bearer '.contractPayoutsManageBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts 503' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsManageBearer($tenant);

            config()->set('payments.circuit_breaker.failure_threshold', 1);
            app(CircuitBreaker::class)->recordFailure('fake');

            return test()->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsViewBearer($tenant);

            contractSubmerchantAccount($tenant, 'fake');

            return test()->getJson('/v1/submerchant-accounts', [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/submerchant-accounts?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractPayoutsViewBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts 401' => function (): TestResponse {
            return test()->getJson('/v1/submerchant-accounts');
        },
        'get /v1/submerchant-accounts 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/submerchant-accounts', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts/{submerchant_account} 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsViewBearer($tenant);

            $account = contractSubmerchantAccount($tenant, 'fake');

            return test()->getJson('/v1/submerchant-accounts/'.$account->id, [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts/{submerchant_account} 401' => function (): TestResponse {
            return test()->getJson('/v1/submerchant-accounts/'.Str::uuid7());
        },
        'get /v1/submerchant-accounts/{submerchant_account} 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/submerchant-accounts/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/submerchant-accounts/{submerchant_account} 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/submerchant-accounts/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractPayoutsViewBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts/{submerchant_account}/refresh 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsManageBearer($tenant);

            $account = contractSubmerchantAccount($tenant, 'fake');

            return test()->postJson('/v1/submerchant-accounts/'.$account->id.'/refresh', [], [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts/{submerchant_account}/refresh 401' => function (): TestResponse {
            return test()->postJson('/v1/submerchant-accounts/'.Str::uuid7().'/refresh', []);
        },
        'post /v1/submerchant-accounts/{submerchant_account}/refresh 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->postJson('/v1/submerchant-accounts/'.Str::uuid7().'/refresh', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['payouts.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts/{submerchant_account}/refresh 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->postJson('/v1/submerchant-accounts/'.Str::uuid7().'/refresh', [], [
                'Authorization' => 'Bearer '.contractPayoutsManageBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/submerchant-accounts/{submerchant_account}/refresh 503' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsManageBearer($tenant);

            $account = app(TenantTransaction::class)->asTenant(
                $tenant->id,
                fn () => SubmerchantAccount::factory()->create([
                    'tenant_id' => $tenant->id,
                    'gateway' => 'fake',
                    'gateway_account_reference' => 'sm_ref_contract_refresh',
                ]),
            );

            config()->set('payments.circuit_breaker.failure_threshold', 1);
            app(CircuitBreaker::class)->recordFailure('fake');

            return test()->postJson('/v1/submerchant-accounts/'.$account->id.'/refresh', [], [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsViewBearer($tenant);

            contractPayout($tenant);

            return test()->getJson('/v1/payouts', [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/payouts?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractPayoutsViewBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts 401' => function (): TestResponse {
            return test()->getJson('/v1/payouts');
        },
        'get /v1/payouts 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/payouts', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts/{payout} 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();
            $bearer = contractPayoutsViewBearer($tenant);

            $payout = contractPayout($tenant);

            return test()->getJson('/v1/payouts/'.$payout->id, [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts/{payout} 401' => function (): TestResponse {
            return test()->getJson('/v1/payouts/'.Str::uuid7());
        },
        'get /v1/payouts/{payout} 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/payouts/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/payouts/{payout} 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractPaymentTenant();

            return test()->getJson('/v1/payouts/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractPayoutsViewBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // Stage-09 plan, Endpoints "GET/POST /v1/events/{event}/signing-keys".
        'get /v1/events/{event}/signing-keys 200' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];

            test()->postJson('/v1/events/'.$event->id.'/signing-keys', [], $headers);

            return test()->getJson('/v1/events/'.$event->id.'/signing-keys', $headers);
        },
        'get /v1/events/{event}/signing-keys 401' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/signing-keys');
        },
        'get /v1/events/{event}/signing-keys 403' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/signing-keys', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/signing-keys 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/signing-keys', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/signing-keys 201' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/signing-keys', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/signing-keys 401' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/signing-keys', []);
        },
        'post /v1/events/{event}/signing-keys 403' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/signing-keys', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/signing-keys 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->postJson('/v1/events/'.Str::uuid7().'/signing-keys', [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // Stage-09 plan, Endpoints "GET /v1/events/{event}/check-in-manifest".
        'get /v1/events/{event}/check-in-manifest 200' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-manifest', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/check-in-manifest 400' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-manifest?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/check-in-manifest 401' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-manifest');
        },
        'get /v1/events/{event}/check-in-manifest 403' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-manifest', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/check-in-manifest 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/check-in-manifest', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        // Stage-09 plan, Endpoints "POST /v1/check-ins".
        'post /v1/check-ins 201' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);

            return test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, $secret), $headers);
        },
        'post /v1/check-ins 200' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);
            $payload = contractCheckInPayload($ticket->id, $event->id, 0, $secret);

            test()->postJson('/v1/check-ins', $payload, $headers);

            return test()->postJson('/v1/check-ins', $payload, $headers);
        },
        'post /v1/check-ins 401' => function (): TestResponse {
            $tenant = contractTenant();
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);

            return test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, $secret));
        },
        'post /v1/check-ins 403' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['events.view']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);

            return test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, $secret), $headers);
        },
        'post /v1/check-ins 404' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            $event = contractEvent($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);

            return test()->postJson('/v1/check-ins', contractCheckInPayload((string) Str::uuid7(), $event->id, 0, $secret), $headers);
        },
        'post /v1/check-ins 409' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);

            test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, $secret, 'device-a'), $headers);

            return test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, $secret, 'device-b'), $headers);
        },
        'post /v1/check-ins 422' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);

            return test()->postJson('/v1/check-ins', contractCheckInPayload($ticket->id, $event->id, 0, 'wrong-secret'), $headers);
        },
        // Stage-09 plan, Endpoints "POST /v1/check-in-batches".
        'post /v1/check-in-batches 200' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);
            $scan = contractCheckInPayload($ticket->id, $event->id, 0, $secret);

            return test()->postJson('/v1/check-in-batches', [
                'device_id' => 'contract-batch-device',
                'scans' => [[
                    'client_scan_id' => $scan['client_scan_id'],
                    'qr_payload' => $scan['qr_payload'],
                    'scanned_at' => $scan['scanned_at'],
                ]],
            ], $headers);
        },
        'post /v1/check-in-batches 401' => function (): TestResponse {
            $tenant = contractTenant();
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);
            $scan = contractCheckInPayload($ticket->id, $event->id, 0, $secret);

            return test()->postJson('/v1/check-in-batches', [
                'device_id' => 'contract-batch-device',
                'scans' => [[
                    'client_scan_id' => $scan['client_scan_id'],
                    'qr_payload' => $scan['qr_payload'],
                    'scanned_at' => $scan['scanned_at'],
                ]],
            ]);
        },
        'post /v1/check-in-batches 403' => function (): TestResponse {
            $tenant = contractTenant();
            $otherTenant = contractTenant();
            $bearer = contractVenueBearer($tenant, ['checkin.manage']);

            return test()->postJson('/v1/check-in-batches', [
                'device_id' => 'contract-batch-device',
                'scans' => [],
            ], [
                'Authorization' => 'Bearer '.$bearer,
                'X-Tenant-Id' => $otherTenant->id,
            ]);
        },
        'post /v1/check-in-batches 422' => function (): TestResponse {
            $tenant = contractTenant();
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            ['ticket' => $ticket, 'event' => $event] = contractCheckInTicket($tenant);
            $secret = contractCheckInSecret($tenant, $event->id);
            $scan = contractCheckInPayload($ticket->id, $event->id, 0, $secret);

            $scans = array_fill(0, 501, [
                'client_scan_id' => (string) Str::uuid7(),
                'qr_payload' => $scan['qr_payload'],
                'scanned_at' => $scan['scanned_at'],
            ]);

            return test()->postJson('/v1/check-in-batches', [
                'device_id' => 'contract-batch-device',
                'scans' => $scans,
            ], $headers);
        },
        // Stage-09 plan, Endpoints "Check-in assignments".
        'get /v1/events/{event}/check-in-assignments 200' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            $memberId = contractCheckInAssignmentMember($tenant);

            test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId], $headers);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-assignments', $headers);
        },
        'get /v1/events/{event}/check-in-assignments 401' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-assignments');
        },
        'get /v1/events/{event}/check-in-assignments 403' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);

            return test()->getJson('/v1/events/'.$event->id.'/check-in-assignments', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/events/{event}/check-in-assignments 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->getJson('/v1/events/'.Str::uuid7().'/check-in-assignments', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/check-in-assignments 201' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            $memberId = contractCheckInAssignmentMember($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId], $headers);
        },
        'post /v1/events/{event}/check-in-assignments 401' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $memberId = contractCheckInAssignmentMember($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId]);
        },
        'post /v1/events/{event}/check-in-assignments 403' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $memberId = contractCheckInAssignmentMember($tenant);

            return test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/check-in-assignments 404' => function (): TestResponse {
            $tenant = contractTenant();
            $memberId = contractCheckInAssignmentMember($tenant);

            return test()->postJson('/v1/events/'.Str::uuid7().'/check-in-assignments', ['user_id' => $memberId], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/events/{event}/check-in-assignments 409' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            $memberId = contractCheckInAssignmentMember($tenant);

            test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId], $headers);

            return test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $memberId], $headers);
        },
        'post /v1/events/{event}/check-in-assignments 422' => function (): TestResponse {
            $tenant = contractTenant();
            $event = contractEvent($tenant);
            $headers = ['Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']), 'X-Tenant-Id' => $tenant->id];
            $outsider = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create());

            return test()->postJson('/v1/events/'.$event->id.'/check-in-assignments', ['user_id' => $outsider->id], $headers);
        },
        'delete /v1/check-in-assignments/{assignment} 401' => function (): TestResponse {
            return test()->deleteJson('/v1/check-in-assignments/'.Str::uuid7());
        },
        'delete /v1/check-in-assignments/{assignment} 403' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->deleteJson('/v1/check-in-assignments/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.scan']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'delete /v1/check-in-assignments/{assignment} 404' => function (): TestResponse {
            $tenant = contractTenant();

            return test()->deleteJson('/v1/check-in-assignments/'.Str::uuid7(), [], [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['checkin.manage']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/daily-sales 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);

            contractSeedDailySales($tenant, $event->id, $ticketType->id);

            return test()->getJson('/v1/reports/daily-sales', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/daily-sales 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/daily-sales?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/daily-sales 401' => function (): TestResponse {
            return test()->getJson('/v1/reports/daily-sales');
        },
        'get /v1/reports/daily-sales 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/daily-sales', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/event-finance 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();
            ['event' => $event] = contractHoldFixture($tenant);

            contractSeedEventFinance($tenant, $event->id);

            return test()->getJson('/v1/reports/event-finance', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/event-finance 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/event-finance?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/event-finance 401' => function (): TestResponse {
            return test()->getJson('/v1/reports/event-finance');
        },
        'get /v1/reports/event-finance 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/event-finance', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/attendance 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();
            ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);

            contractSeedEventAttendance($tenant, $event->id, $ticketType->id);

            return test()->getJson('/v1/reports/attendance', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/attendance 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/attendance?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/reports/attendance 401' => function (): TestResponse {
            return test()->getJson('/v1/reports/attendance');
        },
        'get /v1/reports/attendance 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/reports/attendance', [
                'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['orders.view']),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/exports 202' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->postJson('/v1/exports', ['type' => 'orders'], [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/exports 401' => function (): TestResponse {
            return test()->postJson('/v1/exports', ['type' => 'orders']);
        },
        'post /v1/exports 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->postJson('/v1/exports', ['type' => 'orders'], [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'post /v1/exports 422' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->postJson('/v1/exports', ['type' => 'not_a_real_type'], [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            contractSeedExport($tenant);

            return test()->getJson('/v1/exports', [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports 400' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports?filter[bogus]=1', [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports 401' => function (): TestResponse {
            return test()->getJson('/v1/exports');
        },
        'get /v1/exports 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export} 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            $exportId = contractSeedExport($tenant);

            return test()->getJson('/v1/exports/'.$exportId, [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export} 401' => function (): TestResponse {
            return test()->getJson('/v1/exports/'.Str::uuid7());
        },
        'get /v1/exports/{export} 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export} 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports/'.Str::uuid7(), [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export}/download 200' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            $exportId = contractSeedExport($tenant);
            contractCompleteExport($tenant, $exportId);

            return test()->getJson('/v1/exports/'.$exportId.'/download', [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export}/download 401' => function (): TestResponse {
            return test()->getJson('/v1/exports/'.Str::uuid7().'/download');
        },
        'get /v1/exports/{export}/download 403' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports/'.Str::uuid7().'/download', [
                'Authorization' => 'Bearer '.contractReportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export}/download 404' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            return test()->getJson('/v1/exports/'.Str::uuid7().'/download', [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
        'get /v1/exports/{export}/download 409' => function (): TestResponse {
            ['tenant' => $tenant] = contractHoldTenant();

            $exportId = contractSeedExport($tenant);

            return test()->getJson('/v1/exports/'.$exportId.'/download', [
                'Authorization' => 'Bearer '.contractExportsBearer($tenant),
                'X-Tenant-Id' => $tenant->id,
            ]);
        },
    ];
}

/**
 * A contractHoldTenant() with the fake gateway enabled (stage-08a plan,
 * task breakdown items 3 to 6).
 *
 * @return array{tenant: Tenant, host: string}
 */
function contractPaymentTenant(): array
{
    ['tenant' => $tenant, 'host' => $host] = contractHoldTenant();

    app(TenantTransaction::class)->asPlatform(fn () => $tenant->update(['enabled_gateways' => ['fake']]));

    return ['tenant' => $tenant, 'host' => $host];
}

/**
 * A pending order in a payment-enabled tenant with plenty of inventory,
 * so async methods stay in the offer.
 *
 * @return array{tenant: Tenant, host: string, token: string, orderId: string}
 */
function contractPaymentOrder(): array
{
    ['tenant' => $tenant, 'host' => $host] = contractPaymentTenant();
    ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant, 100);
    $token = contractOrderCustomerBearer($tenant, $host);

    $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
    ], ['Authorization' => 'Bearer '.$token])->json('id');

    return ['tenant' => $tenant, 'host' => $host, 'token' => $token, 'orderId' => $orderId];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function contractInitiatePayment(array $order, array $body, ?string $key): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.$order['token']];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->postJson('http://'.$order['host'].'/v1/storefront/orders/'.$order['orderId'].'/payments', $body, $headers);
}

/**
 * @return TestResponse<JsonResponse>
 */
function contractPostWebhook(string $gateway, string $body, string $signature): TestResponse
{
    return test()->call('POST', '/v1/webhooks/'.$gateway, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $signature,
    ], $body);
}

/**
 * A staff bearer holding ledger.view with confirmed MFA, since the
 * capability is financially privileged (stage-08b plan, Endpoints).
 */
function contractLedgerBearer(Tenant $tenant): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => ['ledger.view']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    return $response->json('access_token');
}

/**
 * A staff bearer holding reports.view with confirmed MFA, since the
 * capability is financially privileged (stage-11 plan task-01 journal).
 */
function contractReportsBearer(Tenant $tenant): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => ['reports.view']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    return $response->json('access_token');
}

function contractSeedDailySales(Tenant $tenant, string $eventId, string $ticketTypeId): void
{
    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $eventId, $ticketTypeId): void {
        DB::table('report_daily_sales')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'sales_date' => now()->toDateString(),
            'tickets_issued_count' => 1,
            'tickets_refunded_count' => 0,
            'gross_amount' => 1000,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

function contractSeedEventFinance(Tenant $tenant, string $eventId): void
{
    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $eventId): void {
        DB::table('report_event_finance')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'orders_paid_count' => 1,
            'refunds_count' => 0,
            'gross_amount' => 1000,
            'gateway_fee_amount' => 30,
            'platform_commission_amount' => 50,
            'tenant_net_amount' => 920,
            'refunded_amount' => 0,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

function contractSeedEventAttendance(Tenant $tenant, string $eventId, string $ticketTypeId): void
{
    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $eventId, $ticketTypeId): void {
        DB::table('report_event_attendance')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'checked_in_count' => 1,
            'duplicate_scan_count' => 0,
            'first_scan_at' => now(),
            'last_scan_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/**
 * A staff bearer holding reports.export with confirmed MFA, since the
 * capability is financially privileged (stage-11 plan task-01 journal).
 */
function contractExportsBearer(Tenant $tenant): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => ['reports.export']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    return $response->json('access_token');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function contractSeedExport(Tenant $tenant, array $overrides = []): string
{
    $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    return app(TenantTransaction::class)->asTenant($tenant->id, fn (): string => Export::factory()->create([
        'tenant_id' => $tenant->id,
        'requested_by_user_id' => $userId,
        ...$overrides,
    ])->id);
}

/**
 * Drives App\Reporting\Models\Export's own claim/complete transitions
 * and attaches a real two-line CSV directly (mirroring
 * ExportLifecycleTest's own completeExport() helper and its docblock's
 * note on why a header-only file mime-sniffs as text/plain, not
 * text/csv), so a completed export with a genuinely downloadable file
 * exists without needing real order/ticket fixtures for this tenant.
 */
function contractCompleteExport(Tenant $tenant, string $exportId): void
{
    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($exportId): void {
        Export::claim($exportId);

        $export = Export::query()->findOrFail($exportId);
        $export->addMediaFromString("id,status\n1,paid\n")
            ->usingFileName('export.csv')
            ->toMediaCollection('export_file');

        Export::complete($exportId, 1);
    });
}

function contractSeedLedgerEntry(Tenant $tenant): void
{
    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): void {
        DB::table('ledger_entries')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'account' => 'tenant_net',
            'direction' => 'credit',
            'amount' => 1000,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => Str::uuid7()->toString(),
            'source_event_id' => Str::uuid7()->toString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
}

/**
 * A staff bearer holding orders.refund with confirmed MFA, since the
 * capability is financially privileged (stage-08b plan, Endpoints).
 */
function contractRefundBearer(Tenant $tenant): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => ['orders.refund']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    return $response->json('access_token');
}

/**
 * A confirmed card payment over the real purchase flow plus a
 * refund-capable staff bearer (stage-08b plan, task breakdown item 8).
 *
 * @return array{tenant: Tenant, host: string, paymentId: string, bearer: string}
 */
function contractConfirmedPayment(): array
{
    $order = contractPaymentOrder();

    $paymentId = contractInitiatePayment(
        $order,
        ['method' => 'card', 'details' => ['token' => 'tok_approve']],
        (string) Str::uuid7(),
    )->json('id');

    return [
        'tenant' => $order['tenant'],
        'host' => $order['host'],
        'paymentId' => $paymentId,
        'bearer' => contractRefundBearer($order['tenant']),
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function contractCreateRefund(array $fixture, array $body, ?string $key): TestResponse
{
    $headers = [
        'Authorization' => 'Bearer '.$fixture['bearer'],
        'X-Tenant-Id' => $fixture['tenant']->id,
    ];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->postJson('/v1/payments/'.$fixture['paymentId'].'/refunds', $body, $headers);
}

/**
 * A staff bearer holding payouts.manage with confirmed MFA, since the
 * capability is financially privileged (stage-08c plan, Endpoints).
 */
function contractPayoutsManageBearer(Tenant $tenant): string
{
    return contractFinanciallyPrivilegedBearer($tenant, ['payouts.manage']);
}

/**
 * A staff bearer holding payouts.view with confirmed MFA, since the
 * capability is financially privileged (stage-08c plan, Endpoints).
 */
function contractPayoutsViewBearer(Tenant $tenant): string
{
    return contractFinanciallyPrivilegedBearer($tenant, ['payouts.view']);
}

/**
 * @param  list<string>  $capabilities
 */
function contractFinanciallyPrivilegedBearer(Tenant $tenant, array $capabilities): string
{
    $user = User::factory()->create();

    $response = test()->postJson('/v1/auth/staff/token', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    app(TenantTransaction::class)->asTenant($tenant->id, function () use ($user, $tenant, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => Role::factory()->create(['tenant_id' => $tenant->id, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    /** @var string $token */
    return $response->json('access_token');
}

function contractSubmerchantAccount(Tenant $tenant, string $gateway): SubmerchantAccount
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => SubmerchantAccount::factory()->create(['tenant_id' => $tenant->id, 'gateway' => $gateway]),
    );
}

function contractPayout(Tenant $tenant): Payout
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Payout::factory()->create(['tenant_id' => $tenant->id]),
    );
}

/**
 * A pending order in the given contractHoldTenant(), created over the
 * real storefront conversion flow (stage-07 plan, task breakdown item
 * 13).
 */
function contractStaffOrder(Tenant $tenant, string $host): string
{
    ['event' => $event, 'ticketType' => $ticketType] = contractHoldFixture($tenant);
    $token = contractOrderCustomerBearer($tenant, $host);

    return test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => contractOrderHold($host, $event->id, $ticketType->id),
    ], ['Authorization' => 'Bearer '.$token])->json('id');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function contractPromoCode(Tenant $tenant, array $attributes = []): PromoCode
{
    return app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => PromoCode::factory()->create(['tenant_id' => $tenant->id, ...$attributes]),
    );
}

/**
 * @return array<string, string>
 */
function contractPromoHeaders(Tenant $tenant): array
{
    return [
        'Authorization' => 'Bearer '.contractVenueBearer($tenant, ['promo_codes.manage']),
        'X-Tenant-Id' => $tenant->id,
    ];
}

/**
 * A fresh customer for the given contractHoldTenant(), authenticated
 * over the Host-resolved token endpoint (stage-07 plan, Endpoints:
 * buyer routes require a customer bearer token).
 */
function contractOrderCustomerBearer(Tenant $tenant, string $host): string
{
    contractCustomer($tenant, ['email' => 'contract-order-buyer@example.com', 'password' => 'password']);

    return test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'contract-order-buyer@example.com',
        'password' => 'password',
    ])->json('access_token');
}

/**
 * @return array{event: Event, ticket: Ticket}
 */
function contractCheckInTicket(Tenant $tenant): array
{
    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'email' => Str::uuid7()->toString().'@example.com']);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'hold_id' => Str::uuid7()->toString(),
        ]);
        $ticket = Ticket::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]);

        return ['event' => $event, 'ticket' => $ticket];
    });
}

function contractCheckInSecret(Tenant $tenant, string $eventId): string
{
    $secret = 'contract-secret-'.$eventId;

    app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $eventId,
            'key_version' => 1,
            'secret' => $secret,
        ]),
    );

    return $secret;
}

/**
 * @return array<string, mixed>
 */
function contractCheckInPayload(string $ticketId, string $eventId, int $rotation, string $secret, string $deviceId = 'contract-device'): array
{
    $signature = hash_hmac('sha256', $ticketId.'|'.$eventId.'|'.$rotation, $secret);

    $qrPayload = rtrim(strtr(base64_encode((string) json_encode([
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'rotation' => $rotation,
        'signature' => $signature,
    ])), '+/', '-_'), '=');

    return [
        'qr_payload' => $qrPayload,
        'device_id' => $deviceId,
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ];
}

function contractOrderHold(string $host, string $eventId, string $ticketTypeId): string
{
    return test()->postJson('http://'.$host.'/v1/storefront/holds', [
        'event_id' => $eventId,
        'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => 1]],
    ])->json('id');
}

/**
 * Every documented "method /path status" triple, content types collapsed.
 *
 * @return list<string>
 */
function documentedResponses(): array
{
    $responses = [];

    foreach (array_keys(OpenApiSpec::documentedResponseSchemas()) as $key) {
        [$method, $path, $status] = explode(' ', $key);

        $responses["{$method} {$path} {$status}"] = true;
    }

    return array_keys($responses);
}

test('documented response is exercised with conformance asserted', function (string $response) {
    $exercisers = documentedResponseExercisers();

    Assert::assertArrayHasKey(
        $response,
        $exercisers,
        "Documented response [{$response}] has no exerciser; register one so its shape is conformance-asserted.",
    );

    $status = (int) substr($response, strrpos($response, ' ') + 1);

    $exercisers[$response]()
        ->assertStatus($status)
        ->assertConformsToOpenApi();
})->with(fn (): array => documentedResponses());

test('every exerciser targets a documented response', function () {
    $documented = documentedResponses();

    foreach (array_keys(documentedResponseExercisers()) as $response) {
        Assert::assertContains(
            $response,
            $documented,
            "Exerciser [{$response}] targets a response that is not documented in docs/openapi/openapi.yaml.",
        );
    }
});
