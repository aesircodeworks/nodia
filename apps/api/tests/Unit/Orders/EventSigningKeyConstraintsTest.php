<?php

use App\EventCatalog\Models\Event;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 1: the status enum column, the two database-level
 * constraints backstopping unique key versions and the single active key
 * per event, and the encrypted secret cast, tested before any endpoint
 * exists.
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

function activeSigningKey(string $tenantId, string $eventId, array $overrides = []): EventSigningKey
{
    return EventSigningKey::factory()->create([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'status' => SigningKeyStatus::Active,
        ...$overrides,
    ]);
}

it('casts a valid status value to the SigningKeyStatus enum', function (): void {
    $key = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, ['key_version' => 1]),
    );

    expect($key->status)->toBe(SigningKeyStatus::Active);
});

it('rejects a status value outside the enum when the model reads the row back', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('event_signing_keys')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'key_version' => 1,
            'secret' => encrypt('irrelevant'),
            'status' => 'bogus',
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->first()->status,
    ))->toThrow(ValueError::class);
});

it('rejects a second key with the same version for the same event through the unique index', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, ['key_version' => 1]),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'key_version' => 1,
            'status' => SigningKeyStatus::Retired,
        ]),
    ))->toThrow(QueryException::class);
});

it('rejects a second active key for the same event through the partial unique index', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, ['key_version' => 1]),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, ['key_version' => 2]),
    ))->toThrow(QueryException::class, 'event_signing_keys_active_event_idx');
});

it('allows a retired key alongside an active key for the same event', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, ['key_version' => 2]),
    );

    $retired = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'key_version' => 1,
            'status' => SigningKeyStatus::Retired,
            'retired_at' => now(),
        ]),
    );

    expect($retired->status)->toBe(SigningKeyStatus::Retired);
});

it('round-trips the secret through the encrypted cast, storing ciphertext and reading back plaintext', function (): void {
    $key = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => activeSigningKey($this->tenantId, $this->eventId, [
            'key_version' => 1,
            'secret' => 'super-secret-material',
        ]),
    );

    expect($key->secret)->toBe('super-secret-material');

    $raw = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('event_signing_keys')->where('id', $key->id)->value('secret'),
    );

    expect($raw)->not->toBe('super-secret-material')
        ->and(decrypt($raw, false))->toBe('super-secret-material');
});
