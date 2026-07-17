<?php

use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MigratedDatabase;
use Tests\Support\Payments\WebhookNegativeProbes;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 6: the webhook negative matrix, run against every
 * gateway the registry binds rather than against FakeGateway by name. A
 * signature verifier is the only authentication the ingestion route has
 * (system-design 7.4), so each registered adapter must reject a missing
 * signature, a body changed after signing, a signature from a foreign
 * key, and a signature outside the freshness window, all with
 * webhook_signature_invalid and all before anything is persisted or
 * queued.
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

/**
 * @return array<string, GatewayAdapter>
 */
function registeredGateways(): array
{
    return app(GatewayRegistry::class)->all();
}

function postGatewayWebhook(string $gateway, string $body, array $headers)
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
    }

    return test()->call('POST', '/v1/webhooks/'.$gateway, [], [], [], $server, $body);
}

function persistedWebhookCount(): int
{
    return app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->count(),
    );
}

it('holds every registered gateway to a webhook negative probe', function (): void {
    $undeclared = array_diff(array_keys(registeredGateways()), array_keys(WebhookNegativeProbes::all()));

    expect($undeclared)->toBe([]);
});

it('rejects every forged webhook for every registered gateway before persisting or queueing anything', function (): void {
    Queue::fake();

    foreach (registeredGateways() as $slug => $adapter) {
        $probe = WebhookNegativeProbes::for($slug);

        $this->assertNotNull($probe, "No webhook negative probe is declared for the [{$slug}] gateway.");

        foreach ($probe->negatives() as $arm => $forge) {
            $forged = $forge($adapter);

            $response = postGatewayWebhook($slug, $forged['body'], $forged['headers']);

            $context = "gateway [{$slug}], negative [{$arm}]";

            $this->assertSame(401, $response->getStatusCode(), $context);
            $this->assertSame('webhook_signature_invalid', $response->json('code'), $context);
            $this->assertSame(0, persistedWebhookCount(), "{$context} persisted a raw webhook row");

            $response->assertConformsToOpenApi();
        }
    }

    Queue::assertNotPushed(ProcessGatewayWebhook::class);
});

it('still accepts what the gateway itself signs, so the negatives are not passing vacuously', function (): void {
    Queue::fake();

    foreach (registeredGateways() as $slug => $adapter) {
        $probe = WebhookNegativeProbes::for($slug);

        if ($probe === null || ! $probe->acceptsValidDelivery) {
            continue;
        }

        $delivery = ($probe->validDelivery)($adapter);

        $response = postGatewayWebhook($slug, $delivery['body'], $delivery['headers']);

        $this->assertSame(200, $response->getStatusCode(), "gateway [{$slug}] rejected its own signed delivery");
        $this->assertSame(1, persistedWebhookCount(), "gateway [{$slug}] did not persist its own signed delivery");

        app(TenantTransaction::class)->asTenant(
            config()->string('tenancy.platform_tenant_id'),
            fn () => DB::table('gateway_webhook_events')->delete(),
        );
    }

    Queue::assertPushed(ProcessGatewayWebhook::class);
});
