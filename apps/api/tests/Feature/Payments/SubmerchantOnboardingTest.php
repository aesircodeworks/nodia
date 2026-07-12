<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Models\SubmerchantAccount;
use App\Payments\Support\CircuitBreaker;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-08c plan, Slice 2: POST /v1/submerchant-accounts starts
 * onboarding, GET list and detail read it back, every stable error code
 * on the create path, capability and MFA gating, and the activity log.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Cache::flush();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );
});

afterEach(function (): void {
    Cache::flush();

    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['outbox_deliveries', 'outbox_events', 'memberships', 'roles', 'submerchant_accounts'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
        Tenant::query()->whereKey($this->otherTenantId)->delete();
    });

    User::query()->delete();
});

function submerchantHeaders(string $tenantId, Capability|array $capabilities): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, $capabilities),
        'X-Tenant-Id' => $tenantId,
    ];
}

function seededSubmerchantAccount(string $tenantId, array $overrides = []): SubmerchantAccount
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => SubmerchantAccount::factory()->create([
        'tenant_id' => $tenantId,
        ...$overrides,
    ]));
}

describe('POST /v1/submerchant-accounts', function (): void {
    it('creates a pending account with the fake gateway onboarding url by default', function (): void {
        $response = $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        );

        $response->assertStatus(201)
            ->assertConformsToOpenApi()
            ->assertJsonPath('gateway', 'fake')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('requirements', []);

        expect($response->json('gateway_account_reference'))->not->toBeNull()
            ->and($response->json('onboarding_url'))->not->toBeNull()
            ->and($response->json('activated_at'))->toBeNull();

        $auditCount = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => DB::table('activity_log')
                ->where('event', 'mutation')
                ->where('description', 'POST /v1/submerchant-accounts')
                ->count(),
        );

        expect($auditCount)->toBeGreaterThan(0);
    });

    it('returns an active account when the gateway approves immediately', function (): void {
        app(FakeGatewayScenarios::class)->scriptSubmerchantCreation(GatewaySubmerchantResult::active('sm_ref_1'));

        $response = $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        );

        $response->assertStatus(201)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('gateway_account_reference', 'sm_ref_1');

        expect($response->json('activated_at'))->not->toBeNull();
    });

    it('rejects an unregistered gateway with gateway_unknown', function (): void {
        $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'nope'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        )
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'gateway_unknown');
    });

    it('rejects a gateway not in enabled_gateways with gateway_not_enabled', function (): void {
        app(TenantTransaction::class)->asPlatform(function (): void {
            Tenant::query()->whereKey($this->tenantId)->update(['enabled_gateways' => []]);
        });

        $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        )
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'gateway_not_enabled');
    });

    it('rejects a repeat start with submerchant_already_onboarded and the existing id', function (): void {
        $existing = seededSubmerchantAccount($this->tenantId, ['gateway' => 'fake']);

        $response = $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        );

        $response->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'submerchant_already_onboarded')
            ->assertJsonPath('existing_id', $existing->id);
    });

    it('returns gateway_unavailable with Retry-After when the circuit breaker is open', function (): void {
        app(CircuitBreaker::class)->recordFailure('fake');
        config()->set('payments.circuit_breaker.failure_threshold', 1);
        app(CircuitBreaker::class)->recordFailure('fake');

        $response = $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        );

        $response->assertStatus(503)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'gateway_unavailable');

        expect($response->headers->get('Retry-After'))->not->toBeNull();
    });

    it('fails validation for a missing gateway', function (): void {
        $this->postJson('/v1/submerchant-accounts', [], submerchantHeaders($this->tenantId, Capability::PayoutsManage))
            ->assertStatus(422)
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('requires the X-Tenant-Id header', function (): void {
        $this->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::PayoutsManage),
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_tenant_header');
    });

    it('denies a bearer without payouts.manage', function (): void {
        $this->postJson(
            '/v1/submerchant-accounts',
            ['gateway' => 'fake'],
            submerchantHeaders($this->tenantId, Capability::PayoutsView),
        )
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('denies a payouts.manage bearer without confirmed MFA', function (): void {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $this->tenantId,
                    'capabilities' => [Capability::PayoutsManage->value],
                ])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $this->postJson('/v1/submerchant-accounts', ['gateway' => 'fake'], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantId,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_enforcement_required');
    });
});

describe('GET /v1/submerchant-accounts', function (): void {
    it('lists the tenant accounts and honors each allowed filter', function (): void {
        $pending = seededSubmerchantAccount($this->tenantId, ['gateway' => 'fake', 'status' => SubmerchantStatus::Pending]);
        seededSubmerchantAccount($this->otherTenantId, ['gateway' => 'fake']);

        $headers = submerchantHeaders($this->tenantId, Capability::PayoutsView);

        $response = $this->getJson('/v1/submerchant-accounts', $headers);
        $response->assertOk()->assertConformsToOpenApi();

        expect(array_column($response->json('data'), 'id'))->toBe([$pending->id]);

        $byStatus = $this->getJson('/v1/submerchant-accounts?filter[status]=pending', $headers);
        expect($byStatus->json('data'))->toHaveCount(1);

        $byOtherStatus = $this->getJson('/v1/submerchant-accounts?filter[status]=active', $headers);
        expect($byOtherStatus->json('data'))->toHaveCount(0);

        $byGateway = $this->getJson('/v1/submerchant-accounts?filter[gateway]=fake', $headers);
        expect($byGateway->json('data'))->toHaveCount(1);
    });

    it('rejects an unknown filter with invalid_query_parameter', function (): void {
        $this->getJson('/v1/submerchant-accounts?filter[bogus]=1', submerchantHeaders($this->tenantId, Capability::PayoutsView))
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a caller-supplied sort with invalid_query_parameter', function (): void {
        $headers = submerchantHeaders($this->tenantId, Capability::PayoutsView);

        $this->getJson('/v1/submerchant-accounts?sort=created_at', $headers)
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');

        $this->getJson('/v1/submerchant-accounts?sort=-created_at', $headers)
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('denies a bearer without payouts.view', function (): void {
        $this->getJson('/v1/submerchant-accounts', submerchantHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/submerchant-accounts/{submerchant_account}', function (): void {
    it('returns the account wire shape', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, [
            'gateway' => 'fake',
            'status' => SubmerchantStatus::ActionRequired,
            'requirements' => ['bank_statement'],
        ]);

        $this->getJson('/v1/submerchant-accounts/'.$account->id, submerchantHeaders($this->tenantId, Capability::PayoutsView))
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $account->id)
            ->assertJsonPath('status', 'action_required')
            ->assertJsonPath('requirements', ['bank_statement']);
    });

    it('returns request.not_found for unknown and cross-tenant ids', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, ['gateway' => 'fake']);

        $this->getJson('/v1/submerchant-accounts/'.Str::uuid7(), submerchantHeaders($this->tenantId, Capability::PayoutsView))
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');

        Auth::forgetGuards();

        $this->getJson('/v1/submerchant-accounts/'.$account->id, submerchantHeaders($this->otherTenantId, Capability::PayoutsView))
            ->assertNotFound()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('denies a bearer without payouts.view', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, ['gateway' => 'fake']);

        $this->getJson('/v1/submerchant-accounts/'.$account->id, submerchantHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('renders only the normalized needs_review status when a webhook carries an unmapped raw status, never the raw string', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, [
            'gateway' => 'fake',
            'status' => SubmerchantStatus::Pending,
            'gateway_account_reference' => 'sm_ref_quarantine',
        ]);

        $rawStatus = 'gateway_status_this_platform_has_never_seen';
        $delivery = app(FakeGateway::class)->submerchantStatusWebhookRaw('sm_ref_quarantine', $rawStatus);

        $this->call('POST', '/v1/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
        ], $delivery->body)->assertStatus(200);

        $response = $this->getJson('/v1/submerchant-accounts/'.$account->id, submerchantHeaders($this->tenantId, Capability::PayoutsView));

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $account->id)
            ->assertJsonPath('status', 'needs_review');

        expect($response->getContent())->not->toContain($rawStatus);
    });
});
