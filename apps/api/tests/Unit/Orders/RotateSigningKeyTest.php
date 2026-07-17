<?php

use App\EventCatalog\Models\Event;
use App\Orders\Actions\RotateSigningKey;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-09 plan, Slice 2: the rotation Action retires the active key
 * via a conditional UPDATE checked by affected-row count and inserts
 * the next version as active, in one transaction; revoke_previous
 * marks the outgoing key revoked instead of retired.
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

it('retires the active key and inserts the next version as active, seeding version 1 first if none exists', function (): void {
    $rotated = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RotateSigningKey::class))($this->eventId),
    );

    [$v1, $active] = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => [
        EventSigningKey::query()->where('event_id', $this->eventId)->where('key_version', 1)->firstOrFail(),
        EventSigningKey::query()->where('event_id', $this->eventId)->where('status', SigningKeyStatus::Active)->firstOrFail(),
    ]);

    expect($rotated->key_version)->toBe(2)
        ->and($rotated->status)->toBe(SigningKeyStatus::Active)
        ->and($active->id)->toBe($rotated->id)
        ->and($v1->status)->toBe(SigningKeyStatus::Retired)
        ->and($v1->retired_at)->not->toBeNull()
        ->and($v1->revoked_at)->toBeNull();
});

it('marks the outgoing key revoked instead of retired when revoke_previous is true', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RotateSigningKey::class))($this->eventId),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => (app(RotateSigningKey::class))($this->eventId, revokePrevious: true),
    );

    $v2 = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('event_id', $this->eventId)->where('key_version', 2)->firstOrFail(),
    );

    expect($v2->status)->toBe(SigningKeyStatus::Revoked)
        ->and($v2->revoked_at)->not->toBeNull()
        ->and($v2->retired_at)->toBeNull();
});

it('leaves exactly one active key and produces strictly monotonic versions across sequential rotations', function (): void {
    $versions = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $first = (app(RotateSigningKey::class))($this->eventId);
        $second = (app(RotateSigningKey::class))($this->eventId);
        $third = (app(RotateSigningKey::class))($this->eventId, revokePrevious: true);

        return [$first->key_version, $second->key_version, $third->key_version];
    });

    $activeCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSigningKey::query()->where('event_id', $this->eventId)->where('status', SigningKeyStatus::Active)->count(),
    );

    expect($versions)->toBe([2, 3, 4])
        ->and($activeCount)->toBe(1);
});

it('conditionally updates by affected-row count: retiring the same key twice affects zero rows the second time', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $key = EventSigningKey::factory()->create([
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'key_version' => 1,
            'status' => SigningKeyStatus::Active,
        ]);

        $first = EventSigningKey::query()
            ->whereKey($key->id)
            ->where('status', SigningKeyStatus::Active)
            ->update(['status' => SigningKeyStatus::Retired, 'retired_at' => now()]);

        $second = EventSigningKey::query()
            ->whereKey($key->id)
            ->where('status', SigningKeyStatus::Active)
            ->update(['status' => SigningKeyStatus::Retired, 'retired_at' => now()]);

        expect($first)->toBe(1)->and($second)->toBe(0);
    });
});
