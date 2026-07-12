<?php

use App\Payments\Actions\TransitionSubmerchantAccount;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 3 unit loop: the full SubmerchantStatus
 * transition matrix. Every legal transition affects exactly one row and
 * lands the target status; every illegal transition affects zero rows
 * and changes nothing. The out-of-order case (active arriving while
 * still pending, skipping under_review) is exercised alongside the
 * others because it lands on a source the matrix already lists for
 * active, not a special case.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
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

function seededTransitionAccount(string $tenantId, SubmerchantStatus $status): SubmerchantAccount
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => SubmerchantAccount::factory()->create([
        'tenant_id' => $tenantId,
        'gateway' => 'fake',
        'status' => $status,
        'requirements' => [],
    ]));
}

$legal = [
    [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview],
    [SubmerchantStatus::Pending, SubmerchantStatus::ActionRequired],
    [SubmerchantStatus::Pending, SubmerchantStatus::Active],
    [SubmerchantStatus::Pending, SubmerchantStatus::Rejected],
    [SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired],
    [SubmerchantStatus::UnderReview, SubmerchantStatus::Active],
    [SubmerchantStatus::UnderReview, SubmerchantStatus::Rejected],
    [SubmerchantStatus::ActionRequired, SubmerchantStatus::UnderReview],
    [SubmerchantStatus::ActionRequired, SubmerchantStatus::Active],
    [SubmerchantStatus::ActionRequired, SubmerchantStatus::Rejected],
    [SubmerchantStatus::Active, SubmerchantStatus::Disabled],
    [SubmerchantStatus::Disabled, SubmerchantStatus::Active],
    [SubmerchantStatus::Rejected, SubmerchantStatus::Pending],
];

$illegal = [
    [SubmerchantStatus::Active, SubmerchantStatus::Pending],
    [SubmerchantStatus::Active, SubmerchantStatus::UnderReview],
    [SubmerchantStatus::Active, SubmerchantStatus::ActionRequired],
    [SubmerchantStatus::Active, SubmerchantStatus::Rejected],
    [SubmerchantStatus::Rejected, SubmerchantStatus::UnderReview],
    [SubmerchantStatus::Rejected, SubmerchantStatus::ActionRequired],
    [SubmerchantStatus::Rejected, SubmerchantStatus::Active],
    [SubmerchantStatus::Rejected, SubmerchantStatus::Disabled],
    [SubmerchantStatus::Disabled, SubmerchantStatus::Pending],
    [SubmerchantStatus::Disabled, SubmerchantStatus::UnderReview],
    [SubmerchantStatus::Disabled, SubmerchantStatus::ActionRequired],
    [SubmerchantStatus::Disabled, SubmerchantStatus::Rejected],
    [SubmerchantStatus::Pending, SubmerchantStatus::Disabled],
    [SubmerchantStatus::UnderReview, SubmerchantStatus::Pending],
    [SubmerchantStatus::UnderReview, SubmerchantStatus::Disabled],
    [SubmerchantStatus::ActionRequired, SubmerchantStatus::Pending],
    [SubmerchantStatus::ActionRequired, SubmerchantStatus::Disabled],
];

foreach ($legal as [$from, $to]) {
    it("transitions {$from->value} to {$to->value}", function () use ($from, $to): void {
        $account = seededTransitionAccount($this->tenantId, $from);

        $result = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => app(TransitionSubmerchantAccount::class)($account->id, $to, ['some_requirement']),
        );

        expect($result)->not->toBeNull()
            ->and($result->status)->toBe($to);

        if ($to === SubmerchantStatus::Active) {
            expect($result->activated_at)->not->toBeNull();
        }

        expect($result->requirements)->toBe(['some_requirement']);
    });
}

foreach ($illegal as [$from, $to]) {
    it("refuses {$from->value} to {$to->value}, affecting zero rows", function () use ($from, $to): void {
        $account = seededTransitionAccount($this->tenantId, $from);

        $result = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => app(TransitionSubmerchantAccount::class)($account->id, $to),
        );

        $fresh = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => SubmerchantAccount::query()->findOrFail($account->id),
        );

        expect($result)->toBeNull()
            ->and($fresh->status)->toBe($from);
    });
}

it('lands active from pending directly, the out-of-order forward-skip rule', function (): void {
    $account = seededTransitionAccount($this->tenantId, SubmerchantStatus::Pending);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(TransitionSubmerchantAccount::class)($account->id, SubmerchantStatus::Active),
    );

    expect($result)->not->toBeNull()
        ->and($result->status)->toBe(SubmerchantStatus::Active)
        ->and($result->activated_at)->not->toBeNull();
});
