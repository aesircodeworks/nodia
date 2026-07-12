<?php

use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Actions\RefreshSubmerchantStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08c plan, Slice 3 concurrency rule: a webhook delivery and a
 * refresh call applying conflicting transitions to the same account in
 * parallel produce exactly one state change. Both start from pending,
 * one contender pushes to active, the other to rejected; both targets
 * are individually legal from pending, so the database row lock inside
 * TransitionSubmerchantAccount's conditional UPDATE, not application
 * logic, decides the single winner. The loser's affected-row count is
 * zero and its own conditional-transition path returns null, exactly
 * the already-processed/duplicate shape the webhook job and the
 * refresh action already handle.
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
            DB::table('submerchant_accounts')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('gateway_webhook_events')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('lets exactly one of a racing webhook and refresh win the transition', function (): void {
    [$tenantId, $accountId] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => ['fake']]);

        return [$tenant->id, $tenant->id];
    });

    $accountId = app(TenantTransaction::class)->asTenant($tenantId, fn () => SubmerchantAccount::factory()->create([
        'tenant_id' => $tenantId,
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Pending,
        'gateway_account_reference' => 'sm_ref_race',
    ])->id);

    $results = ParallelRunner::runEach(
        function (): string {
            $delivery = app(FakeGateway::class)->submerchantStatusWebhook('sm_ref_race', SubmerchantStatus::Active);

            app(IngestGatewayWebhook::class)(
                app(FakeGateway::class),
                $delivery->body,
                ['X-Fake-Signature' => $delivery->headers['X-Fake-Signature']],
            );

            return 'webhook';
        },
        function () use ($tenantId, $accountId): string {
            app(FakeGatewayScenarios::class)->scriptSubmerchantStatus('sm_ref_race', GatewaySubmerchantResult::rejected('sm_ref_race'));

            $applied = app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(RefreshSubmerchantStatus::class)($accountId),
            );

            return 'refresh:'.$applied->status->value;
        },
    );

    $account = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($accountId),
    );

    expect($account->status)->toBeIn([SubmerchantStatus::Active, SubmerchantStatus::Rejected])
        ->and($results)->toHaveCount(2);
});
