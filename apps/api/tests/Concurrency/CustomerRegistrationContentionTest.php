<?php

use App\Identity\Models\Customer;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Slice 6's create guard (stage-03 plan, task breakdown item 13): two
 * parallel POST /v1/customers requests presenting the same email in the
 * same tenant must resolve to exactly one created row, surfaced as
 * customer_email_taken for the loser rather than a 500, guarded by the
 * customers (tenant_id, email) unique index rather than a read-then-write
 * existence check (App\Identity\Actions\RegisterCustomer). Driven through
 * the real HTTP kernel in forked workers
 * (tests/Concurrency/TenantDomainContentionTest.php's pattern) so each
 * contender runs the full production path, host resolution included.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // customers carries no platform write policy (stage-03 plan Data
    // model, "standard single-table policy" precedent from memberships),
    // so a blanket nodia_platform delete silently affects zero rows;
    // each tenant's own customers must be cleared under nodia_app before
    // the tenant delete below, or it fails a foreign key violation
    // (customers_tenant_id_foreign), mirroring
    // tests/Contract/DocumentedResponseCoverageTest.php's own afterEach.
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, code: string|null}
 */
function handleCustomerRegistrationRequest(string $host, array $payload): array
{
    $request = Request::create('http://'.$host.'/v1/customers', 'POST', [], [], [], [
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

it('resolves parallel guest creation with one email in one tenant to exactly one row', function (): void {
    ['host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    $results = ParallelRunner::run(2, fn (PDO $pdo): array => handleCustomerRegistrationRequest($host, [
        'email' => 'raced-guest@example.com',
        'name' => 'Raced Guest',
    ]));

    $statuses = collect($results)->pluck('status')->sort()->values()->all();

    expect($statuses)->toBe([201, 409])
        ->and(collect($results)->firstWhere('status', 409)['code'])->toBe('customer_email_taken');

    $rows = app(TenantTransaction::class)->asPlatform(
        fn () => Customer::query()->where('email', 'raced-guest@example.com')->get(),
    );

    expect($rows)->toHaveCount(1);
});
