<?php

use App\Payments\Actions\StartSubmerchantOnboarding;
use App\Payments\Data\StartSubmerchantOnboardingData;
use App\Payments\Exceptions\SubmerchantAlreadyOnboardedException;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08c plan, Slice 2 concurrency rule: parallel onboarding starts
 * for the same tenant and gateway produce exactly one
 * submerchant_accounts row and one gateway createSubmerchant call side
 * effect; every loser gets submerchant_already_onboarded. Written before
 * the (tenant_id, gateway) unique constraint's insert path merges, per
 * the master plan's non-negotiable rule for guarded transitions. The
 * insert-before-gateway-call ordering in StartSubmerchantOnboarding is
 * what keeps a losing worker from ever reaching the gateway: this is
 * verified by exactly one row carrying a non-null
 * gateway_account_reference (the fake gateway always returns one), not
 * by shared in-memory call counting, since ParallelRunner forks separate
 * OS processes that share nothing but the database.
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
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('produces exactly one row and one gateway call from parallel duplicate onboarding starts', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    $results = ParallelRunner::run(4, function () use ($tenantId): string {
        try {
            app(TenantTransaction::class)->asTenant($tenantId, fn () => app(StartSubmerchantOnboarding::class)(
                StartSubmerchantOnboardingData::from(['gateway' => 'fake']),
            ));

            return 'won';
        } catch (SubmerchantAlreadyOnboardedException) {
            return 'lost';
        }
    });

    $won = array_values(array_filter($results, fn (string $result): bool => $result === 'won'));
    $lost = array_values(array_filter($results, fn (string $result): bool => $result === 'lost'));

    [$rowCount, $referencedCount] = app(TenantTransaction::class)->asTenant($tenantId, fn (): array => [
        SubmerchantAccount::query()->where('gateway', 'fake')->count(),
        SubmerchantAccount::query()->where('gateway', 'fake')->whereNotNull('gateway_account_reference')->count(),
    ]);

    expect($won)->toHaveCount(1)
        ->and($lost)->toHaveCount(3)
        ->and($rowCount)->toBe(1)
        ->and($referencedCount)->toBe(1);
});
