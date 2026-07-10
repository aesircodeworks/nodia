<?php

use App\Identity\Mail\CustomerClaimMail;
use App\Identity\Models\Customer;
use App\Identity\Support\ClaimToken;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: POST /v1/auth/customer/claim and
 * .../claim/confirm. Every case invites the claim token by mailing it
 * through the real endpoint and recovering it from Mail::fake()'s
 * captured CustomerClaimMail, mirroring
 * tests/Feature/Identity/InvitationAcceptanceTest.php's own "prove the
 * endpoint end to end" precedent.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // customers carries no platform write policy (stage-03 plan Data
    // model, "standard single-table policy" precedent from memberships),
    // so a blanket nodia_platform delete silently affects zero rows;
    // each tenant's own customers must be cleared under nodia_app before
    // the tenant delete below, or it fails a foreign key violation
    // (customers_tenant_id_foreign), mirroring
    // tests/Contract/DocumentedResponseCoverageTest.php's own afterEach.
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function claimTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createClaimableGuest(string $tenantId, array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $tenantId, 'password' => null, ...$attributes]),
    );
}

describe('POST /v1/auth/customer/claim', function (): void {
    it('always renders 202 and mails a token for a genuine unclaimed guest', function (): void {
        ['tenant' => $tenant, 'host' => $host] = claimTenant();
        $guest = createClaimableGuest($tenant->id, ['email' => 'guest-claim@example.com']);

        Mail::fake();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim', ['email' => 'guest-claim@example.com'])
            ->assertStatus(202)
            ->assertConformsToOpenApi();

        Mail::assertSent(CustomerClaimMail::class, fn (CustomerClaimMail $mail): bool => $mail->hasTo($guest->email));
    });

    it('renders 202 for an unknown email without mailing anything', function (): void {
        ['host' => $host] = claimTenant();

        Mail::fake();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim', ['email' => 'nobody@example.com'])
            ->assertStatus(202);

        Mail::assertNothingSent();
    });

    it('renders 202 for an already-claimed customer without mailing anything', function (): void {
        ['tenant' => $tenant, 'host' => $host] = claimTenant();
        app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::factory()->create(['tenant_id' => $tenant->id, 'email' => 'already-claimed@example.com', 'password' => 'password']),
        );

        Mail::fake();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim', ['email' => 'already-claimed@example.com'])
            ->assertStatus(202);

        Mail::assertNothingSent();
    });
});

describe('POST /v1/auth/customer/claim/confirm', function (): void {
    it('sets the password and enables login', function (): void {
        ['tenant' => $tenant, 'host' => $host] = claimTenant();
        $guest = createClaimableGuest($tenant->id, ['email' => 'confirm-claim@example.com']);

        $token = ClaimToken::issue($guest->id);

        $this->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => $token,
            'password' => 'a-new-password',
        ])
            ->assertNoContent()
            ->assertConformsToOpenApi();

        $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'confirm-claim@example.com',
            'password' => 'a-new-password',
        ])->assertOk();
    });

    it('rejects a tampered token with claim_token_invalid', function (): void {
        ['host' => $host] = claimTenant();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => 'not-a-real-token',
            'password' => 'a-new-password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'claim_token_invalid']);
    });

    it('rejects an expired token with claim_token_expired', function (): void {
        ['tenant' => $tenant, 'host' => $host] = claimTenant();
        $guest = createClaimableGuest($tenant->id, ['email' => 'expired-claim@example.com']);

        $token = ClaimToken::issue($guest->id);

        $this->travel(config()->integer('identity.claim_token_ttl_minutes') + 1)->minutes();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => $token,
            'password' => 'a-new-password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'claim_token_expired']);
    });

    it('rejects a confirmation for a customer who already holds a password', function (): void {
        ['tenant' => $tenant, 'host' => $host] = claimTenant();
        $guest = createClaimableGuest($tenant->id, ['email' => 'reclaim@example.com']);
        $token = ClaimToken::issue($guest->id);

        $this->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => $token,
            'password' => 'first-password',
        ])->assertNoContent();

        $this->postJson('http://'.$host.'/v1/auth/customer/claim/confirm', [
            'token' => $token,
            'password' => 'second-password',
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'customer_already_claimed']);
    });
});
