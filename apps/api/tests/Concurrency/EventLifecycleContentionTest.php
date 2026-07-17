<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\TenantStaff;

/**
 * The publish/cancel races of the stage-05a plan, Slice 4 (task breakdown
 * item 9), plus the canceled-event immutability race the round-1 review
 * flagged, driven through the real HTTP kernel in forked workers so each
 * contender runs the full production path: tenancy.admin route group, the
 * request transaction, the Action's conditional write, and the row lock
 * that decides the winner. The invariant asserted across every race is
 * that recorded outbox events exactly match the committed row-count-1
 * transitions: no event without its transition, and no canceled event
 * ever mutated after the fact.
 */
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
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

/**
 * @return array{0: string, 1: Event, 2: string}
 */
function lifecycleFixture(EventStatus $status): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $bearer = TenantStaff::token($tenantId, [
        Capability::EventsView,
        Capability::EventsManage,
        Capability::EventsPublish,
    ]);

    $event = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => $status, 'timezone' => 'UTC']),
    );

    return [$tenantId, $event, $bearer];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, code: string|null}
 */
function handleLifecycleRequest(string $method, string $uri, string $bearer, string $tenantId, array $payload = []): array
{
    $request = Request::create($uri, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_TENANT_ID' => $tenantId,
    ], $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
    ];
}

function outboxCountOfType(string $tenantId, string $type, string $aggregateId): int
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', $type)->where('aggregate_id', $aggregateId)->count(),
    );
}

function reloadLifecycleEvent(string $tenantId, string $eventId): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::query()->whereKey($eventId)->firstOrFail(),
    );
}

it('resolves parallel publishes of one draft to exactly one winner and one EventPublished row', function () {
    [$tenantId, $event, $bearer] = lifecycleFixture(EventStatus::Draft);

    $results = ParallelRunner::run(4, fn (PDO $pdo): array => handleLifecycleRequest(
        'POST',
        '/v1/events/'.$event->id.'/publish',
        $bearer,
        $tenantId,
    ));

    $statuses = collect($results)->pluck('status');

    expect($statuses->filter(fn (int $s): bool => $s === 200))->toHaveCount(1)
        ->and($statuses->reject(fn (int $s): bool => in_array($s, [200, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('catalog.event_not_publishable');
        }
    }

    expect(outboxCountOfType($tenantId, 'EventPublished', $event->id))->toBe(1)
        ->and(reloadLifecycleEvent($tenantId, $event->id)->status)->toBe(EventStatus::Published);
});

it('resolves parallel cancels of one draft to exactly one winner and one EventCanceled row', function () {
    [$tenantId, $event, $bearer] = lifecycleFixture(EventStatus::Draft);

    $results = ParallelRunner::run(4, fn (PDO $pdo): array => handleLifecycleRequest(
        'POST',
        '/v1/events/'.$event->id.'/cancel',
        $bearer,
        $tenantId,
    ));

    $statuses = collect($results)->pluck('status');

    expect($statuses->filter(fn (int $s): bool => $s === 200))->toHaveCount(1)
        ->and($statuses->reject(fn (int $s): bool => in_array($s, [200, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('catalog.event_not_cancelable');
        }
    }

    expect(outboxCountOfType($tenantId, 'EventCanceled', $event->id))->toBe(1)
        ->and(reloadLifecycleEvent($tenantId, $event->id)->status)->toBe(EventStatus::Canceled);
});

it('reconciles a publish-versus-cancel race so outbox rows match the committed transitions', function () {
    [$tenantId, $event, $bearer] = lifecycleFixture(EventStatus::Draft);

    $results = ParallelRunner::runEach(
        fn (PDO $pdo): array => handleLifecycleRequest('POST', '/v1/events/'.$event->id.'/publish', $bearer, $tenantId),
        fn (PDO $pdo): array => handleLifecycleRequest('POST', '/v1/events/'.$event->id.'/cancel', $bearer, $tenantId),
    );

    [$publishResult, $cancelResult] = $results;

    // Cancel is legal from both draft and published, so it always commits a
    // transition (row-count 1) regardless of interleaving; publish only wins
    // if it commits before the cancel, from the draft state.
    expect($cancelResult['status'])->toBe(200);

    if ($publishResult['status'] !== 200) {
        expect($publishResult['status'])->toBe(409)
            ->and($publishResult['code'])->toBe('catalog.event_not_publishable');
    }

    expect(outboxCountOfType($tenantId, 'EventCanceled', $event->id))->toBe(1)
        ->and(outboxCountOfType($tenantId, 'EventPublished', $event->id))->toBe($publishResult['status'] === 200 ? 1 : 0)
        ->and(reloadLifecycleEvent($tenantId, $event->id)->status)->toBe(EventStatus::Canceled);
});

it('never mutates a canceled event when an update races a cancel', function () {
    [$tenantId, $event, $bearer] = lifecycleFixture(EventStatus::Draft);

    $results = ParallelRunner::runEach(
        fn (PDO $pdo): array => handleLifecycleRequest('POST', '/v1/events/'.$event->id.'/cancel', $bearer, $tenantId),
        fn (PDO $pdo): array => handleLifecycleRequest('PATCH', '/v1/events/'.$event->id, $bearer, $tenantId, ['timezone' => 'America/Chicago']),
    );

    [$cancelResult, $updateResult] = $results;

    expect($cancelResult['status'])->toBe(200);

    $final = reloadLifecycleEvent($tenantId, $event->id);

    expect($final->status)->toBe(EventStatus::Canceled);

    if ($updateResult['status'] === 200) {
        // The update committed while the event was still a draft; the cancel
        // then transitioned the already-modified row.
        expect($final->timezone)->toBe('America/Chicago');
    } else {
        // The cancel won the row lock first; the update saw a canceled event
        // through the locking recheck and left the row untouched.
        expect($updateResult['status'])->toBe(409)
            ->and($updateResult['code'])->toBe('catalog.event_immutable')
            ->and($final->timezone)->toBe('UTC');
    }
});
