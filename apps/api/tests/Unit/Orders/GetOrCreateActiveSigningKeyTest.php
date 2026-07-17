<?php

use App\EventCatalog\Models\Event;
use App\Orders\Actions\GetOrCreateActiveSigningKey;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Support\DerivedTicketSigningKeyProvider;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 2: get-or-create of the version 1 key is
 * race-safe and seeds the secret from the exact Stage 7 HKDF derivation.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('event_signing_keys')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('seeds an event with no keys as version 1, active, with the secret matching the Stage 7 HKDF derivation', function (): void {
    $key = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(GetOrCreateActiveSigningKey::class))($this->eventId),
    );

    $expectedSecret = app(DerivedTicketSigningKeyProvider::class)->keyForEvent($this->eventId);

    expect($key->key_version)->toBe(1)
        ->and($key->status)->toBe(SigningKeyStatus::Active)
        ->and($key->secret)->toBe($expectedSecret);
});

it('returns the existing active key without creating a second row when one already exists', function (): void {
    $existing = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'key_version' => 3,
            'status' => SigningKeyStatus::Active,
        ]),
    );

    $found = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(GetOrCreateActiveSigningKey::class))($this->eventId),
    );

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('event_id', $this->eventId)->count(),
    );

    expect($found->id)->toBe($existing->id)
        ->and($count)->toBe(1);
});

it('is idempotent: calling it again after a key already exists never creates a second row', function (): void {
    $action = app(GetOrCreateActiveSigningKey::class);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($action): void {
        $first = $action($this->eventId);
        $second = $action($this->eventId);

        expect($second->id)->toBe($first->id)
            ->and(EventSigningKey::query()->where('event_id', $this->eventId)->count())->toBe(1);
    });
});
