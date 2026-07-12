<?php

use App\EventCatalog\Models\Event;
use App\Orders\Actions\RotateSigningKey;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-09 plan, Slice 2 concurrency rule: parallel rotation requests
 * for one event leave exactly one active key and strictly monotonic
 * versions. RotateSigningKey's retiring conditional UPDATE serializes
 * on the active row's lock; a loser's affected-row count comes back
 * zero once the winner commits, and the Action retries against the
 * winner's now-current active key instead of failing, so every worker
 * succeeds and the versions it produces are all distinct and increasing.
 */
const WORKERS = 6;

beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('event_signing_keys')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('leaves exactly one active key with strictly monotonic versions under parallel rotations', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $eventId = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId])->id,
    );

    $results = ParallelRunner::run(WORKERS, function () use ($tenantId, $eventId): int {
        return app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => (app(RotateSigningKey::class))($eventId)->key_version,
        );
    });

    [$versions, $activeCount] = app(TenantTransaction::class)->asTenant($tenantId, fn () => [
        EventSigningKey::query()->where('event_id', $eventId)->pluck('key_version')->sort()->values()->all(),
        EventSigningKey::query()->where('event_id', $eventId)->where('status', SigningKeyStatus::Active)->count(),
    ]);

    // One seeded version 1 (retired by the first winning rotation) plus
    // one new version per worker: WORKERS + 1 rows, versions 1..N+1.
    expect($results)->toHaveCount(WORKERS)
        ->and(array_unique($results))->toHaveCount(WORKERS)
        ->and($versions)->toBe(range(1, WORKERS + 1))
        ->and($activeCount)->toBe(1);
});
