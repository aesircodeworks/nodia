<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Inventory\Actions\AttachHoldCustomer;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 review round 3: the attachment conditional UPDATE must guard
 * hold liveness itself, not rely on the caller's earlier checks, so a
 * sweeper or release racing between ConvertHoldToOrder's guards and the
 * attach cannot let an invalid hold convert.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return array{holdId: string, customerId: string}
 */
function attachFixture(string $tenantId, HoldStatus $status = HoldStatus::Active, int $expiresInMinutes = 10): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $status, $expiresInMinutes): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => Str::uuid7()->toString().'@example.com',
        ]);

        $hold = Hold::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'customer_id' => null,
            'status' => $status,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);

        return ['holdId' => $hold->id, 'customerId' => $customer->id];
    });
}

it('attaches a live anonymous hold exactly once and stays idempotent for the owner', function (): void {
    ['holdId' => $holdId, 'customerId' => $customerId] = attachFixture($this->tenantId);

    $attach = fn (): bool => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AttachHoldCustomer::class)($holdId, $customerId),
    );

    expect($attach())->toBeTrue()
        ->and($attach())->toBeTrue();
});

it('refuses a hold that is no longer active', function (HoldStatus $status): void {
    ['holdId' => $holdId, 'customerId' => $customerId] = attachFixture($this->tenantId, $status);

    $attached = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AttachHoldCustomer::class)($holdId, $customerId),
    );

    expect($attached)->toBeFalse();
})->with([
    'expired' => [HoldStatus::Expired],
    'released' => [HoldStatus::Released],
    'committed' => [HoldStatus::Committed],
]);

it('refuses an active hold whose expires_at has passed', function (): void {
    ['holdId' => $holdId, 'customerId' => $customerId] = attachFixture($this->tenantId, HoldStatus::Active, -1);

    $attached = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(AttachHoldCustomer::class)($holdId, $customerId),
    );

    expect($attached)->toBeFalse();
});
