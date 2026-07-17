<?php

use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\Payments\FakeGatewaySignature;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 5: POST /v1/webhooks/{gateway}, the raw
 * ingestion path. Persist first, always 2xx after persist, duplicates
 * insert-conflict into a no-op (system-design 7.4, 13).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->delete(),
    );
});

function postWebhook(FakeWebhookDelivery $delivery, string $gateway = 'fake', ?string $signature = null)
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...$delivery->serverHeaders(),
    ];

    if ($signature !== null) {
        $server['HTTP_X_FAKE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/v1/webhooks/'.$gateway, [], [], [], $server, $delivery->body);
}

function webhookRowCount(): int
{
    return app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->count(),
    );
}

describe('POST /v1/webhooks/{gateway}', function (): void {
    it('persists a validly signed webhook and returns 200', function (): void {
        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref', Money::of(125, 'USD'));

        $response = postWebhook($delivery);

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect(webhookRowCount())->toBe(1);
    });

    it('persists one row for the same gateway event id delivered twice, both returning 200', function (): void {
        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref', Money::of(125, 'USD'), eventId: 'evt_dup_serial');

        postWebhook($delivery)->assertStatus(200);
        postWebhook($delivery)->assertStatus(200);

        expect(webhookRowCount())->toBe(1);
    });

    it('rejects an invalid signature with 401 and persists nothing', function (): void {
        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref', Money::of(125, 'USD'));

        $response = postWebhook($delivery, signature: 'bogus');

        $response->assertStatus(401)->assertConformsToOpenApi();
        expect($response->json('code'))->toBe('webhook_signature_invalid')
            ->and(webhookRowCount())->toBe(0);
    });

    it('rejects a validly signed body with no gateway event id as 422', function (): void {
        $body = (string) json_encode(['type' => 'payment.confirmed']);
        $delivery = new FakeWebhookDelivery($body, FakeGatewaySignature::headersFor($body));

        $response = postWebhook($delivery);

        $response->assertStatus(422)->assertConformsToOpenApi();
        expect($response->json('code'))->toBe('webhook_unparseable')
            ->and(webhookRowCount())->toBe(0);
    });

    it('renders request.not_found for a gateway with no registered adapter', function (): void {
        $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref', Money::of(125, 'USD'));

        postWebhook($delivery, gateway: 'stripe')->assertStatus(404);

        expect(webhookRowCount())->toBe(0);
    });
});
