<?php

declare(strict_types=1);

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-12 plan, Slice 1 Feature tests: "exactly one CustomerAnonymized
 * outbox row with the payload above and the request's correlation ID; a
 * failed request records nothing." Mirrors
 * tests/Feature/Identity/CustomerRegisteredOutboxTest.php's own structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('data_subject_requests')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function anonymizedOutboxBearer(string $tenantId): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => ['customers.erase']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('persists exactly one CustomerAnonymized outbox row for a successful erasure over HTTP', function () {
    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId, 'password' => 'password']),
    );

    $correlationId = 'customer-anonymized-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
            'Authorization' => 'Bearer '.anonymizedOutboxBearer($this->tenantId),
            'X-Tenant-Id' => $this->tenantId,
        ]);

    $response->assertCreated();

    $requestId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerAnonymized')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('CustomerAnonymized')
        ->and($row->aggregate_type)->toBe('customer')
        ->and($row->aggregate_id)->toBe($customer->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'customer_id' => $customer->id,
            'data_subject_request_id' => $requestId,
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'customer_id',
            'data_subject_request_id',
        ])
        ->and($row->payload)->not->toHaveKey('email')
        ->and($row->payload)->not->toHaveKey('name');
});

it('records nothing when a repeat erasure fails with customer_already_anonymized', function () {
    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId, 'password' => 'password']),
    );

    $headers = [
        'Authorization' => 'Bearer '.anonymizedOutboxBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], $headers)
        ->assertCreated();

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], $headers)
        ->assertStatus(409);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerAnonymized')->count(),
    );

    expect($count)->toBe(1);
});

it('records nothing when the erasure request fails validation', function () {
    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'not-a-type'], [
        'Authorization' => 'Bearer '.anonymizedOutboxBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])->assertStatus(422);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerAnonymized')->count(),
    );

    expect($count)->toBe(0);
});
