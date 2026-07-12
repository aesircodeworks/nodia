<?php

declare(strict_types=1);

use App\EventCatalog\Actions\CreateEvent;
use App\EventCatalog\Data\CreateEventData;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\LaravelData\Optional;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 5: EventCreated producer on
 * CreateEvent, mirroring tests/Feature/Identity/UserInvitedOutboxTest.php's
 * own structure (stage-04 plan, Slice 6 precedent).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('venues')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('persists exactly one EventCreated outbox row on a successful create over HTTP', function () {
    $correlationId = 'event-created-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/events', [
            'name' => ['en' => 'Outbox Gala'],
            'description' => ['en' => 'Testing the outbox.'],
            'venue_id' => null,
            'is_virtual' => true,
            'virtual_event_url' => 'https://example.test/stream',
            'start_at' => '2026-08-01T18:00:00Z',
            'end_at' => '2026-08-01T21:00:00Z',
            'timezone' => 'UTC',
        ]);

    $response->assertCreated();

    $eventId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCreated')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('EventCreated')
        ->and($row->aggregate_type)->toBe('event')
        ->and($row->aggregate_id)->toBe($eventId)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toBe(['event_id' => $eventId]);
});

it('records nothing when the create request fails validation', function () {
    $this->postJson('/v1/events', [
        'name' => [],
        'description' => ['en' => 'X'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T17:00:00Z',
        'timezone' => 'UTC',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCreated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the create is denied for missing capability', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
        ->postJson('/v1/events', [
            'name' => ['en' => 'Denied Gala'],
            'description' => ['en' => 'X'],
            'venue_id' => null,
            'is_virtual' => true,
            'virtual_event_url' => 'https://example.test/stream',
            'start_at' => '2026-08-01T18:00:00Z',
            'end_at' => '2026-08-01T21:00:00Z',
            'timezone' => 'UTC',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_capability');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCreated')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    $tenantId = $this->tenantId;

    $data = new CreateEventData(
        ['en' => 'Rollback Gala'],
        ['en' => 'X'],
        null,
        true,
        'https://example.test/stream',
        '2026-08-01T18:00:00Z',
        '2026-08-01T21:00:00Z',
        'UTC',
        new Optional,
        new Optional,
    );

    try {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($data): void {
            app(CreateEvent::class)($data);
            throw new RuntimeException('force rollback after EventCreated record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCreated')->count(),
    );

    expect($count)->toBe(0);
});
