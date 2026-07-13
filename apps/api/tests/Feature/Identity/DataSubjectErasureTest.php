<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\ClaimToken;
use App\Models\User;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-12 plan, Slice 1 (task breakdown item 3): the erasure branch of
 * POST /v1/customers/{customer}/data-subject-requests. Erasure runs
 * synchronously, so every case here proves the whole flow end to end over
 * HTTP: anonymization, token revocation, downstream login and claim
 * denial, and the conflict and authorization error codes.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    ['tenant' => $this->tenant, 'host' => $this->host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    $this->tenantId = $this->tenant->id;
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('data_subject_requests')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

/**
 * @param  list<string>  $capabilities
 */
function erasureBearer(string $tenantId, array $capabilities = ['customers.erase']): string
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => $capabilities])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function erasureCustomer(string $tenantId, array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('returns 201 with a completed request and anonymizes the customer in place', function () {
    $customer = erasureCustomer($this->tenantId, [
        'email' => 'erase-me@example.com',
        'name' => 'Erase Me',
        'password' => 'password',
    ]);

    $response = $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertCreated()
        ->assertConformsToOpenApi()
        ->assertJsonPath('customer_id', $customer->id)
        ->assertJsonPath('type', 'erasure')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('download_url', null);

    expect($response->json('completed_at'))->not->toBeNull();

    $fresh = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Customer::query()->findOrFail($customer->id));

    expect($fresh->name)->not->toBe('Erase Me')
        ->and($fresh->email)->not->toBe('erase-me@example.com')
        ->and($fresh->password)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull();
});

it('rejects the guest-claim flow for an anonymized customer', function () {
    $customer = erasureCustomer($this->tenantId, ['password' => null]);
    $token = ClaimToken::issue($customer->id);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])->assertCreated();

    $this->postJson('http://'.$this->host.'/v1/auth/customer/claim/confirm', [
        'token' => $token,
        'password' => 'new-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'claim_token_invalid');
});

it('rejects login with the customer\'s pre-erasure credentials afterward', function () {
    $customer = erasureCustomer($this->tenantId, [
        'email' => 'old-login@example.com',
        'password' => 'old-password',
    ]);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])->assertCreated();

    $this->postJson('http://'.$this->host.'/v1/auth/customer/token', [
        'email' => 'old-login@example.com',
        'password' => 'old-password',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_credentials');
});

it('rejects a pre-erasure access token and its refresh token afterward', function () {
    $customer = erasureCustomer($this->tenantId, [
        'email' => 'tokens@example.com',
        'password' => 'password',
    ]);

    $pair = $this->postJson('http://'.$this->host.'/v1/auth/customer/token', [
        'email' => 'tokens@example.com',
        'password' => 'password',
    ])->json();

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])->assertCreated();

    $this->postJson('http://'.$this->host.'/v1/auth/customer/logout', [], [
        'Authorization' => 'Bearer '.$pair['access_token'],
    ])->assertStatus(401);

    $this->postJson('http://'.$this->host.'/v1/auth/customer/refresh', [
        'refresh_token' => $pair['refresh_token'],
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'refresh_token_reused');
});

it('returns 409 customer_already_anonymized on a repeat erasure', function () {
    $customer = erasureCustomer($this->tenantId, ['password' => 'password']);
    $headers = [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], $headers)
        ->assertCreated();

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], $headers)
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'customer_already_anonymized');
});

it('returns 409 data_subject_request_already_open for an already-open request', function () {
    $customer = erasureCustomer($this->tenantId, ['password' => 'password']);
    $tenantId = $this->tenantId;

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $customer): void {
        DataSubjectRequest::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'requested_by_user_id' => User::factory()->create()->id,
        ]);
    });

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'data_subject_request_already_open');
});

it('returns 403 without customers.erase', function () {
    $customer = erasureCustomer($this->tenantId);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId, ['events.view']),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'missing_capability');
});

it('returns 404 for a customer of another tenant', function () {
    $customer = erasureCustomer($this->otherTenantId);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(404)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.not_found');
});

it('rejects an unaccepted type with request.validation_failed', function () {
    $customer = erasureCustomer($this->tenantId);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'export'], [
        'Authorization' => 'Bearer '.erasureBearer($this->tenantId),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(422)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.validation_failed');
});

it('records an activity log row naming the acting user', function () {
    $customer = erasureCustomer($this->tenantId);
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);
    $tenantId = $this->tenantId;

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId, 'capabilities' => ['customers.erase']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'erasure'], [
        'Authorization' => 'Bearer '.$token,
        'X-Tenant-Id' => $tenantId,
    ])->assertCreated();

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'mutation')
            ->where('tenant_id', $tenantId)
            ->where('causer_id', $user->id)
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->causer_type)->toBe(User::class);
});
