<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The domain-endpoint races of the stage-02 plan, Slice 5, driven through
 * the real HTTP kernel in forked workers so each contender runs the full
 * production path: platform route group, request transaction, Action, and
 * the database constraint that decides the winner. The forked child
 * inherits the booted application; ParallelRunner purges the parent's
 * connection before forking, so each worker's kernel opens its own.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, code: string|null}
 */
function handleDomainRequest(string $method, string $uri, array $payload = []): array
{
    $request = Request::create($uri, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
    ];
}

it('resolves parallel registration of one domain for two tenants to exactly one winner via the unique index', function () {
    [$tenantA, $tenantB] = app(TenantTransaction::class)->asPlatform(
        fn () => [Tenant::factory()->create(), Tenant::factory()->create()],
    );

    $register = fn (string $tenantId): Closure => fn (PDO $pdo): array => handleDomainRequest(
        'POST',
        '/v1/tenants/'.$tenantId.'/domains',
        ['domain' => 'Raced.Example.Com'],
    );

    $results = ParallelRunner::runEach($register($tenantA->id), $register($tenantB->id));

    $statuses = collect($results)->pluck('status')->sort()->values()->all();

    expect($statuses)->toBe([201, 409])
        ->and(collect($results)->firstWhere('status', 409)['code'])->toBe('domain_already_registered');

    $rows = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('domain', 'raced.example.com')->get(),
    );

    expect($rows)->toHaveCount(1);
});

it('leaves exactly one is_primary row when two domains of one tenant race for primary', function () {
    [$tenant, $target1, $target2] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();

        TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'original.example.com', 'is_primary' => true]);

        return [
            $tenant,
            TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'contender-one.example.com']),
            TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'domain' => 'contender-two.example.com']),
        ];
    });

    $promote = fn (string $domainId): Closure => fn (PDO $pdo): array => handleDomainRequest(
        'PATCH',
        '/v1/tenant-domains/'.$domainId,
        ['is_primary' => true],
    );

    $results = ParallelRunner::runEach($promote($target1->id), $promote($target2->id));

    // Interleaved runs lose one contender to the partial unique index
    // (409); fully serialized runs let both promotions succeed in turn.
    // Either way the invariant holds: exactly one primary row survives,
    // and it is one of the contenders, never the original.
    $statuses = collect($results)->pluck('status');

    expect($statuses->contains(200))->toBeTrue()
        ->and($statuses->reject(fn (int $status) => in_array($status, [200, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('tenant_domain_is_primary');
        }
    }

    $primaries = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->get(),
    );

    expect($primaries)->toHaveCount(1)
        ->and(in_array($primaries->first()->id, [$target1->id, $target2->id], true))->toBeTrue();
});
