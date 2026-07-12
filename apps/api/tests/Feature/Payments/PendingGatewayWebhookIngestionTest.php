<?php

use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08d plan, Slice 3: the skeleton's per-gateway verifier rejects
 * everything, so ingestion for its slug fails closed before persisting,
 * distinct from the gateway_not_configured code the skeleton's other
 * operations throw.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    config(['payments.gateways.pending.enabled' => true]);
    app()->forgetScopedInstances();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->delete(),
    );
});

it('rejects every webhook for the pending gateway slug with webhook_signature_invalid and persists nothing', function (): void {
    $response = test()->call('POST', '/v1/webhooks/pending', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(['id' => 'evt_1', 'type' => 'payment.confirmed']));

    $response->assertStatus(401)->assertConformsToOpenApi();
    expect($response->json('code'))->toBe('webhook_signature_invalid');

    $rowCount = app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->count(),
    );

    expect($rowCount)->toBe(0);
});
