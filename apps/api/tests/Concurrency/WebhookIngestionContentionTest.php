<?php

use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Gateways\FakeGateway;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08a plan, Slice 5 concurrency: ingestion uniqueness is
 * invariant-guarding, so parallel duplicate deliveries must insert
 * exactly one row. Written before IngestGatewayWebhook exists per the
 * master plan's non-negotiable rule.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->delete(),
    );
});

it('inserts exactly one row under parallel duplicate deliveries', function (): void {
    $delivery = app(FakeGateway::class)->confirmationWebhook('fake_ref_parallel', Money::of(125, 'USD'), eventId: 'evt_dup_parallel');

    $results = ParallelRunner::run(4, function () use ($delivery): string {
        return app(IngestGatewayWebhook::class)(
            app(FakeGateway::class),
            $delivery->body,
            $delivery->headers,
        );
    });

    $count = app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->count(),
    );

    expect($count)->toBe(1)
        ->and(array_unique($results))->toHaveCount(1);
});
