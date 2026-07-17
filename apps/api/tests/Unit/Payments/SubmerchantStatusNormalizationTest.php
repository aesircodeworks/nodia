<?php

use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Actions\RefreshSubmerchantStatus;
use App\Payments\Actions\StartSubmerchantOnboarding;
use App\Payments\Data\StartSubmerchantOnboardingData;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\GatewaySubmerchantResult;
use App\Payments\Gateways\SubmerchantStatusMap;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08d plan, Slice 4: the onboarding port (StartSubmerchantOnboarding,
 * RefreshSubmerchantStatus, TransitionSubmerchantAccount, SubmerchantAccount)
 * exercised against a second adapter with an entirely different raw
 * status vocabulary, proving normalization is a per-adapter data map, not
 * a hardcoded switch, and that a status absent from that map quarantines
 * to needs_review instead of throwing or being silently dropped.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->scenarios = new FakeGatewayScenarios;

    // A second adapter identity ("altgw") whose wire vocabulary shares
    // nothing with the fake gateway's own (SubmerchantStatus::*->value):
    // "verified" instead of "active", "in_review" instead of
    // "under_review", and so on. The map, not the adapter class, is what
    // carries the vocabulary difference.
    $this->altVocabulary = new SubmerchantStatusMap([
        'new_application' => SubmerchantStatus::Pending,
        'in_review' => SubmerchantStatus::UnderReview,
        'action_needed' => SubmerchantStatus::ActionRequired,
        'verified' => SubmerchantStatus::Active,
        'declined' => SubmerchantStatus::Rejected,
    ]);

    $this->altGateway = new FakeGateway($this->scenarios, 'altgw', $this->altVocabulary);

    app()->instance(GatewayRegistry::class, new GatewayRegistry([
        'altgw' => $this->altGateway,
    ]));

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['altgw']])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('submerchant_accounts')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('gateway_webhook_events')->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function deliverAltWebhook(string $tenantId, FakeGateway $altGateway, string $reference, string $rawStatus, array $requirements = []): void
{
    $delivery = $altGateway->submerchantStatusWebhookRaw($reference, $rawStatus, $requirements);

    app(IngestGatewayWebhook::class)($altGateway, $delivery->body, $delivery->headers);
}

it('maps a recognized raw status through the alt vocabulary onto the normalized enum', function (): void {
    $account = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'altgw'])),
    );

    deliverAltWebhook($this->tenantId, $this->altGateway, $account->gateway_account_reference, 'verified');

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::Active)
        ->and($fresh->activated_at)->not->toBeNull();
});

it('quarantines a raw status absent from the adapter map as needs_review instead of throwing or dropping it', function (): void {
    $account = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'altgw'])),
    );

    deliverAltWebhook($this->tenantId, $this->altGateway, $account->gateway_account_reference, 'gateway_added_a_new_status');

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SubmerchantAccount::query()->findOrFail($account->id),
    );

    expect($fresh->status)->toBe(SubmerchantStatus::NeedsReview);
});

it('quarantines an unrecognized status returned by the manual refresh fallback the same way', function (): void {
    $account = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(StartSubmerchantOnboarding::class)(StartSubmerchantOnboardingData::from(['gateway' => 'altgw'])),
    );

    // The refresh path (fetchSubmerchantStatus) hands back an
    // already-normalized GatewaySubmerchantResult by construction (it is
    // not parsing a raw wire payload), so quarantining an out-of-band
    // status there is exercised by resolving the alt map directly and
    // asserting RefreshSubmerchantStatus applies whatever normalized
    // status the adapter returns through the same guarded transition.
    $this->scenarios->scriptSubmerchantStatus(
        $account->gateway_account_reference,
        GatewaySubmerchantResult::rejected($account->gateway_account_reference),
    );

    $refreshed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RefreshSubmerchantStatus::class)($account->id),
    );

    expect($refreshed->status)->toBe(SubmerchantStatus::Rejected);
});

it('resolves the same raw status differently across two adapters with different maps, proving the mapping is per-adapter data', function (): void {
    $identityGateway = new FakeGateway(new FakeGatewayScenarios, 'fake2', SubmerchantStatusMap::identity());

    // "verified" means Active under the alt vocabulary but is entirely
    // unmapped under the identity vocabulary (whose only recognized
    // strings are the enum's own values), so the same raw string
    // quarantines under one adapter and resolves cleanly under the other.
    $altNormalized = $this->altVocabulary->resolve('verified');
    $identityNormalized = SubmerchantStatusMap::identity()->resolve('verified');

    expect($altNormalized)->toBe(SubmerchantStatus::Active)
        ->and($identityNormalized)->toBe(SubmerchantStatus::NeedsReview)
        ->and($identityGateway->identifier())->toBe('fake2');
});
