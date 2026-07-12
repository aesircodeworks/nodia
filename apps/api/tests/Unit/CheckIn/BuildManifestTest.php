<?php

use App\CheckIn\Actions\BuildManifest;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 3: BuildManifest consumes the Orders ticket
 * listing Action (Data objects in and out, no Orders model import,
 * enforced globally by the Architecture suite) and overlays accepted
 * check_ins rows.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('check_ins')->where('tenant_id', $this->tenantId)->delete();
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

function buildManifestFixture(string $tenantId, int $count): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $count): array {
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

        $tickets = collect(range(1, $count))->map(fn () => Ticket::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
        ]));

        return [$event->id, $tickets];
    });
}

it('returns every ticket with status, rotation counter, and no checked-in overlay by default', function (): void {
    [$eventId, $tickets] = buildManifestFixture($this->tenantId, 2);

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(BuildManifest::class))($eventId),
    );

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry->status)->toBe('issued')
            ->and($entry->rotationCounter)->toBe(0)
            ->and($entry->checkedInAt)->toBeNull();
    }

    expect($entries->pluck('ticketId')->all())->toBe($tickets->pluck('id')->sort()->values()->all());
});

it('overlays checked_in_at from the accepted check-in row', function (): void {
    [$eventId, $tickets] = buildManifestFixture($this->tenantId, 1);
    $ticket = $tickets->first();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticket, $eventId): void {
        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $eventId,
            'user_id' => User::factory()->create()->id,
            'scanned_at' => '2026-07-01T10:00:00Z',
        ]);
    });

    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(BuildManifest::class))($eventId)->first(),
    );

    expect($entry->checkedInAt)->toBe('2026-07-01T10:00:00Z');
});

it('shows no checked_in_at for a ticket with only a duplicate check-in row', function (): void {
    [$eventId, $tickets] = buildManifestFixture($this->tenantId, 1);
    $ticket = $tickets->first();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticket, $eventId): void {
        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $ticket->id,
            'event_id' => $eventId,
            'user_id' => User::factory()->create()->id,
            'result' => CheckInResult::Duplicate,
            'scanned_at' => '2026-07-01T10:00:00Z',
        ]);
    });

    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(BuildManifest::class))($eventId)->first(),
    );

    expect($entry->checkedInAt)->toBeNull();
});

it('excludes an entry unchanged since filter[updated_since] on both sides of the overlay', function (): void {
    [$eventId, $tickets] = buildManifestFixture($this->tenantId, 2);
    $unchanged = $tickets->first();
    $checkedInAfter = $tickets->last();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($unchanged, $checkedInAfter, $eventId): void {
        DB::table('tickets')->where('id', $unchanged->id)->update(['updated_at' => '2026-01-01T00:00:00Z']);
        DB::table('tickets')->where('id', $checkedInAfter->id)->update(['updated_at' => '2026-01-01T00:00:00Z']);

        CheckIn::factory()->create([
            'tenant_id' => $this->tenantId,
            'ticket_id' => $checkedInAfter->id,
            'event_id' => $eventId,
            'user_id' => User::factory()->create()->id,
            'scanned_at' => '2026-06-01T00:00:00Z',
            'synced_at' => '2026-06-01T00:00:00Z',
        ]);
    });

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(BuildManifest::class))($eventId, '2026-02-01T00:00:00Z'),
    );

    expect($entries->pluck('ticketId')->all())->toBe([$checkedInAfter->id]);
});

it('includes an entry whose ticket updated_at alone crosses filter[updated_since]', function (): void {
    [$eventId, $tickets] = buildManifestFixture($this->tenantId, 1);
    $ticket = $tickets->first();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticket): void {
        DB::table('tickets')->where('id', $ticket->id)->update([
            'status' => TicketStatus::Canceled->value,
            'updated_at' => '2026-06-01T00:00:00Z',
        ]);
    });

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(BuildManifest::class))($eventId, '2026-02-01T00:00:00Z'),
    );

    expect($entries)->toHaveCount(1);
});
