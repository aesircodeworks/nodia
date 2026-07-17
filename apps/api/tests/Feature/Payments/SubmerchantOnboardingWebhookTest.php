<?php

use App\Identity\Capability;
use App\Models\User;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 3: the sub-merchant onboarding lifecycle driven
 * by gateway webhooks, plus the manual refresh fallback. The webhook
 * carries no tenant context; the account is resolved by (gateway,
 * gateway_account_reference) under the platform role, activity-logged,
 * mirroring the stage-08a payment-webhook path.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Cache::flush();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );
});

afterEach(function (): void {
    Cache::flush();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['submerchant_accounts', 'memberships', 'roles'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('gateway_webhook_events')->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });

    User::query()->delete();
});

it('transitions pending to active on a webhook and sets activated_at', function (): void {
    $account = seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_active',
    ]);

    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_active', SubmerchantStatus::Active))
        ->assertStatus(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::Active)
        ->and($fresh->activated_at)->not->toBeNull();
});

it('carries the requirements list on an action_required webhook', function (): void {
    $account = seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_action',
    ]);

    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook(
        'sm_ref_action',
        SubmerchantStatus::ActionRequired,
        ['bank_statement', 'proof_of_address'],
    ))->assertStatus(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::ActionRequired)
        ->and($fresh->requirements)->toBe(['bank_statement', 'proof_of_address']);
});

it('transitions pending to rejected on a webhook', function (): void {
    $account = seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_rejected',
    ]);

    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_rejected', SubmerchantStatus::Rejected))
        ->assertStatus(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::Rejected);
});

it('returns a rejected account to pending on a retry webhook', function (): void {
    $account = seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Rejected,
        'gateway_account_reference' => 'sm_ref_retry',
    ]);

    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_retry', SubmerchantStatus::Pending))
        ->assertStatus(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::Pending);
});

it('logs one platform_role_use activity entry for the webhook tenant resolution', function (): void {
    seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_audit',
    ]);

    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_audit', SubmerchantStatus::Active))
        ->assertStatus(200);

    $count = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'platform_role_use')
            ->where('properties->gateway_event_id', DB::table('gateway_webhook_events')->value('gateway_event_id'))
            ->count(),
    );

    expect($count)->toBe(1);
});

it('produces one state change and one activity log entry under duplicate webhook delivery', function (): void {
    seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_dup',
    ]);

    $delivery = app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_dup', SubmerchantStatus::Active, eventId: 'evt_submerchant_dup');

    foreach (range(1, 3) as $ignored) {
        deliverWebhook($delivery)->assertStatus(200);
    }

    $rowId = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('gateway_webhook_events')->value('id'),
    );

    // Re-running the job explicitly on top of the queued duplicates
    // proves the guard is the row's own status, not queue dedup.
    (new ProcessGatewayWebhook((string) $rowId))->handle();

    [$rowCount, $auditCount, $statusCount] = app(TenantTransaction::class)->asPlatform(fn (): array => [
        DB::table('gateway_webhook_events')->count(),
        ActivityLogEntry::query()
            ->where('event', 'platform_role_use')
            ->where('properties->gateway_event_id', 'evt_submerchant_dup')
            ->count(),
        DB::table('submerchant_accounts')->where('gateway_account_reference', 'sm_ref_dup')->where('status', SubmerchantStatus::Active->value)->count(),
    ]);

    expect($rowCount)->toBe(1)
        ->and($auditCount)->toBe(1)
        ->and($statusCount)->toBe(1);
});

it('ignores a webhook for an unmatched gateway account reference', function (): void {
    deliverWebhook(app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_unknown', SubmerchantStatus::Active))
        ->assertStatus(200);

    $row = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('gateway_webhook_events')->first(),
    );

    expect($row->status)->toBe('ignored');
});

describe('POST /v1/submerchant-accounts/{submerchant_account}/refresh', function (): void {
    it('applies the fetched status and returns the updated account', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, [
            'gateway' => 'fake',
            'status' => SubmerchantStatus::Pending,
            'gateway_account_reference' => 'sm_ref_refresh',
        ]);

        app(FakeGatewayScenarios::class)->scriptSubmerchantStatus('sm_ref_refresh', GatewaySubmerchantResult::active('sm_ref_refresh'));

        $response = $this->postJson(
            '/v1/submerchant-accounts/'.$account->id.'/refresh',
            [],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        );

        $response->assertStatus(200)
            ->assertConformsToOpenApi()
            ->assertJsonPath('status', 'active');

        $fresh = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => SubmerchantAccount::query()->findOrFail($account->id),
        );

        expect($fresh->status)->toBe(SubmerchantStatus::Active)
            ->and($fresh->activated_at)->not->toBeNull();
    });

    it('returns request.not_found for an unknown id', function (): void {
        $this->postJson(
            '/v1/submerchant-accounts/'.(string) Str::uuid7().'/refresh',
            [],
            submerchantHeaders($this->tenantId, Capability::PayoutsManage),
        )
            ->assertStatus(404)
            ->assertJsonPath('code', 'request.not_found');
    });

    it('denies a bearer without payouts.manage', function (): void {
        $account = seededSubmerchantAccount($this->tenantId, ['gateway' => 'fake']);

        $this->postJson(
            '/v1/submerchant-accounts/'.$account->id.'/refresh',
            [],
            submerchantHeaders($this->tenantId, Capability::PayoutsView),
        )
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});
