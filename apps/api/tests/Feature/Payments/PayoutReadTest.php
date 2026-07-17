<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Models\Payout;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-08c plan, Slice 5 (T9): cursor-paginated payout reads behind
 * payouts.view, both financially privileged (Stage 3) so a confirmed
 * MFA session is required.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['memberships', 'roles', 'payouts'] as $table) {
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

function payoutHeaders(string $tenantId, Capability|array $capabilities): array
{
    return [
        'Authorization' => 'Bearer '.TenantStaff::token($tenantId, $capabilities),
        'X-Tenant-Id' => $tenantId,
    ];
}

function seededPayout(string $tenantId, array $overrides = []): Payout
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Payout::factory()->create([
        'tenant_id' => $tenantId,
        ...$overrides,
    ]));
}

describe('GET /v1/payouts', function (): void {
    it('cursor-paginates newest first and honors each allowed filter', function (): void {
        $older = seededPayout($this->tenantId, ['gateway' => 'fake', 'status' => PayoutStatus::Paid, 'created_at' => now()->subMinute()]);
        $newer = seededPayout($this->tenantId, ['gateway' => 'fake', 'status' => PayoutStatus::Pending]);
        $headers = payoutHeaders($this->tenantId, Capability::PayoutsView);

        $page = $this->getJson('/v1/payouts?per_page=1', $headers);
        $page->assertOk()->assertConformsToOpenApi();

        expect($page->json('data'))->toHaveCount(1)
            ->and($page->json('data.0.id'))->toBe($newer->id)
            ->and($page->json('meta.next_cursor'))->not->toBeNull();

        $rest = $this->getJson('/v1/payouts?per_page=1&cursor='.$page->json('meta.next_cursor'), $headers);
        expect($rest->json('data.0.id'))->toBe($older->id);

        $byStatus = $this->getJson('/v1/payouts?filter[status]=paid', $headers);
        expect(array_column($byStatus->json('data'), 'id'))->toBe([$older->id]);

        $byGateway = $this->getJson('/v1/payouts?filter[gateway]=fake', $headers);
        expect($byGateway->json('data'))->toHaveCount(2);

        $byOtherGateway = $this->getJson('/v1/payouts?filter[gateway]=bogus', $headers);
        expect($byOtherGateway->json('data'))->toHaveCount(0);
    });

    it('returns the documented payout wire shape including discrepancy', function (): void {
        $payout = seededPayout($this->tenantId, [
            'money' => Money::of(12_345, 'USD'),
            'status' => PayoutStatus::Paid,
            'executed_at' => now(),
            'reconciled_at' => now(),
            'discrepancy_amount' => 500,
        ]);

        $response = $this->getJson('/v1/payouts', payoutHeaders($this->tenantId, Capability::PayoutsView));
        $response->assertOk()->assertConformsToOpenApi();

        expect($response->json('data.0'))->toMatchArray([
            'id' => $payout->id,
            'gateway' => 'fake',
            'gateway_reference' => $payout->gateway_reference,
            'amount' => ['amount' => 12_345, 'currency' => 'USD'],
            'status' => 'paid',
            'discrepancy' => ['amount' => 500, 'currency' => 'USD'],
        ]);
    });

    it('returns a null discrepancy when reconciliation found no divergence', function (): void {
        seededPayout($this->tenantId, ['discrepancy_amount' => null]);

        $response = $this->getJson('/v1/payouts', payoutHeaders($this->tenantId, Capability::PayoutsView));

        expect($response->json('data.0.discrepancy'))->toBeNull();
    });

    it('rejects unknown filters with invalid_query_parameter', function (): void {
        $this->getJson('/v1/payouts?filter[bogus]=1', payoutHeaders($this->tenantId, Capability::PayoutsView))
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('never leaks another tenant payouts', function (): void {
        seededPayout($this->tenantId);
        seededPayout($this->otherTenantId);

        $list = $this->getJson('/v1/payouts', payoutHeaders($this->tenantId, Capability::PayoutsView));

        expect($list->json('data'))->toHaveCount(1);
    });

    it('denies a bearer without payouts.view', function (): void {
        $this->getJson('/v1/payouts', payoutHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('denies a payouts.view bearer without confirmed MFA', function (): void {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role_id' => Role::factory()->create([
                    'tenant_id' => $this->tenantId,
                    'capabilities' => [Capability::PayoutsView->value],
                ])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $this->getJson('/v1/payouts', [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantId,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_enforcement_required');
    });
});

describe('GET /v1/payouts/{payout}', function (): void {
    it('returns the payout wire shape', function (): void {
        $payout = seededPayout($this->tenantId, ['status' => PayoutStatus::InTransit]);

        $this->getJson('/v1/payouts/'.$payout->id, payoutHeaders($this->tenantId, Capability::PayoutsView))
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $payout->id)
            ->assertJsonPath('status', 'in_transit');
    });

    it('returns request.not_found for unknown and cross-tenant ids', function (): void {
        $payout = seededPayout($this->tenantId);

        $this->getJson('/v1/payouts/'.Str::uuid7(), payoutHeaders($this->tenantId, Capability::PayoutsView))
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');

        Auth::forgetGuards();

        $this->getJson('/v1/payouts/'.$payout->id, payoutHeaders($this->otherTenantId, Capability::PayoutsView))
            ->assertNotFound()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('denies a bearer without payouts.view', function (): void {
        $payout = seededPayout($this->tenantId);

        $this->getJson('/v1/payouts/'.$payout->id, payoutHeaders($this->tenantId, Capability::OrdersView))
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });
});
