<?php

use App\Identity\Actions\ClaimGuestAccount;
use App\Identity\Data\ConfirmClaimData;
use App\Identity\Exceptions\CustomerAlreadyClaimedException;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

beforeEach(function (): void {
    MigratedDatabase::ensure();
    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('customers')->where('tenant_id', $this->tenantId)->delete(),
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('allows exactly one overlapping guest claim to choose the password', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId, 'password' => null]),
    );
    $token = ClaimToken::issue($customer->id);
    $tenantId = $this->tenantId;

    $results = ParallelRunner::runEach(
        function (PDO $pdo) use ($tenantId, $token): string {
            try {
                app(TenantTransaction::class)->asTenant(
                    $tenantId,
                    fn () => app(ClaimGuestAccount::class)(new ConfirmClaimData($token, 'first-password')),
                );

                return 'first';
            } catch (CustomerAlreadyClaimedException) {
                return 'lost';
            }
        },
        function (PDO $pdo) use ($tenantId, $token): string {
            try {
                app(TenantTransaction::class)->asTenant(
                    $tenantId,
                    fn () => app(ClaimGuestAccount::class)(new ConfirmClaimData($token, 'second-password')),
                );

                return 'second';
            } catch (CustomerAlreadyClaimedException) {
                return 'lost';
            }
        },
    );

    expect($results)->toContain('lost');

    $password = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::query()->findOrFail($customer->id)->password,
    );

    expect(Hash::check('first-password', $password) !== Hash::check('second-password', $password))->toBeTrue();
});
