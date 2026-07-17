<?php

declare(strict_types=1);

use App\Identity\Actions\RegisterCustomer;
use App\Identity\Data\RegisterCustomerData;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\LaravelData\Optional;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan, Slice 6 (task-13): CustomerRegistered producer on
 * RegisterCustomer. Successful registration over HTTP persists exactly one
 * outbox row with the correct type, aggregate, tenant, correlation ID, and
 * payload shape; failing requests record nothing.
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
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('persists exactly one CustomerRegistered outbox row for a guest registration over HTTP', function () {
    $correlationId = 'customer-guest-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('http://'.$this->host.'/v1/customers', [
            'email' => 'outbox-guest@example.com',
            'name' => 'Outbox Guest',
        ]);

    $response->assertCreated()
        ->assertJsonPath('is_claimed', false);

    $customerId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerRegistered')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('CustomerRegistered')
        ->and($row->aggregate_type)->toBe('customer')
        ->and($row->aggregate_id)->toBe($customerId)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'customer_id' => $customerId,
            'is_guest' => true,
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'customer_id',
            'is_guest',
        ])
        ->and($row->payload)->not->toHaveKey('email')
        ->and($row->payload)->not->toHaveKey('name');
});

it('persists is_guest false for a full registration with password', function () {
    $correlationId = 'customer-registered-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('http://'.$this->host.'/v1/customers', [
            'email' => 'outbox-registered@example.com',
            'name' => 'Outbox Registered',
            'password' => 'a-real-password',
        ]);

    $response->assertCreated()
        ->assertJsonPath('is_claimed', true);

    $customerId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerRegistered')->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toMatchArray([
            'customer_id' => $customerId,
            'is_guest' => false,
        ])
        ->and($rows->first()->correlation_id)->toBe($correlationId);
});

it('records nothing when the registration request fails validation', function () {
    $this->postJson('http://'.$this->host.'/v1/customers', [
        'email' => 'not-an-email',
        'name' => '',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerRegistered')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when registration collides with an existing email', function () {
    $this->postJson('http://'.$this->host.'/v1/customers', [
        'email' => 'already-outbox@example.com',
        'name' => 'First',
    ])->assertCreated();

    $this->postJson('http://'.$this->host.'/v1/customers', [
        'email' => 'already-outbox@example.com',
        'name' => 'Second',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'customer_email_taken');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerRegistered')->count(),
    );

    expect($count)->toBe(1);
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
            app(RegisterCustomer::class)(new RegisterCustomerData(
                'rollback-customer@example.com',
                'Rollback',
                new Optional,
                new Optional,
            ));
            throw new RuntimeException('force rollback after CustomerRegistered record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerRegistered')->count(),
    );

    expect($count)->toBe(0);
});
