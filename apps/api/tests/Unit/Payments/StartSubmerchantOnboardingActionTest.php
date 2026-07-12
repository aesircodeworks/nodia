<?php

use App\Payments\Actions\StartSubmerchantOnboarding;
use App\Payments\Data\StartSubmerchantOnboardingData;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Exceptions\GatewayNotEnabledException;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\GatewayUnknownException;
use App\Payments\Exceptions\SubmerchantAlreadyOnboardedException;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 2 unit loop: the (tenant_id, gateway) unique
 * constraint guards against a second start, the enabled_gateways read
 * goes through App\Tenancy\Actions\ResolveEnabledGateways rather than
 * touching App\Tenancy\Models\Tenant directly (the architecture suite's
 * boundary test tightens this further), and the gateway is called
 * exactly once per successful start.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake'], 'settlement_currency' => 'USD'])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('submerchant_accounts')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('creates a pending row, calls the gateway once, and persists its result', function (): void {
    $account = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])),
    );

    expect($account)->toBeInstanceOf(SubmerchantAccount::class)
        ->and($account->status)->toBe(SubmerchantStatus::Pending)
        ->and($account->gateway_account_reference)->not->toBeNull()
        ->and(app(FakeGatewayScenarios::class)->submerchantCreationCallCountFor($this->tenantId, 'fake'))->toBe(1);
});

it('applies a synchronous approval through the guarded transition, landing active with an audit entry', function (): void {
    app(FakeGatewayScenarios::class)->scriptSubmerchantCreation(GatewaySubmerchantResult::active('sm_ref_sync'));

    $account = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])),
    );

    expect($account->status)->toBe(SubmerchantStatus::Active)
        ->and($account->activated_at)->not->toBeNull();

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => ActivityLogEntry::query()
            ->where('event', 'submerchant_state_changed')
            ->where('properties->submerchant_account_id', $account->id)
            ->where('properties->status', SubmerchantStatus::Active->value)
            ->count(),
    );

    expect($count)->toBe(1);
});

it('rolls back the pending row when the gateway call fails so onboarding can be retried', function (): void {
    app(FakeGatewayScenarios::class)->failNextSubmerchantCreation();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        expect(fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])))
            ->toThrow(GatewayUnavailableException::class);
    });

    $count = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => SubmerchantAccount::query()->count());

    expect($count)->toBe(0);

    $retried = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])),
    );

    expect($retried->status)->toBe(SubmerchantStatus::Pending)
        ->and($retried->gateway_account_reference)->not->toBeNull();
});

it('throws gateway_unknown for an unregistered adapter without inserting a row', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        expect(fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'nope'])))
            ->toThrow(GatewayUnknownException::class);
    });

    $count = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => SubmerchantAccount::query()->count());

    expect($count)->toBe(0);
});

it('throws gateway_not_enabled without calling the gateway', function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->update(['enabled_gateways' => []]);
    });

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        expect(fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])))
            ->toThrow(GatewayNotEnabledException::class);
    });

    expect(app(FakeGatewayScenarios::class)->submerchantCreationCallCountFor($this->tenantId, 'fake'))->toBe(0);
});

it('throws submerchant_already_onboarded with the existing id on a repeat start', function (): void {
    $existing = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake'])),
    );

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($existing): void {
        try {
            app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'fake']));

            $this->fail('Expected SubmerchantAlreadyOnboardedException.');
        } catch (SubmerchantAlreadyOnboardedException $e) {
            expect($e->problemExtensions())->toBe(['existing_id' => $existing->id]);
        }
    });

    expect(app(FakeGatewayScenarios::class)->submerchantCreationCallCountFor($this->tenantId, 'fake'))->toBe(1);
});
