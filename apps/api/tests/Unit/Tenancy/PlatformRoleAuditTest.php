<?php

use App\Support\Tenancy\PlatformRoleAudit;
use Illuminate\Http\Request;
use Monolog\Level;
use Tests\Support\LogCapture;

it('records a single info entry under the stable audit message name', function () {
    $handler = LogCapture::fake();

    $request = Request::create('/v1/tenants', 'GET');
    $request->headers->set('X-Correlation-Id', 'unit-correlation-id');

    app(PlatformRoleAudit::class)->recordRequest($request);

    $entries = LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE);

    expect($handler->getRecords())->toHaveCount(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->level)->toBe(Level::Info);
});

it('carries the platform role, correlation id, method, and normalized path in the entry context', function () {
    $handler = LogCapture::fake();

    $request = Request::create('/v1/tenants/abc/domains?page=2', 'POST');
    $request->headers->set('X-Correlation-Id', 'unit-correlation-id');

    app(PlatformRoleAudit::class)->recordRequest($request);

    expect(LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE)[0]->context)->toBe([
        'role' => 'nodia_platform',
        'correlation_id' => 'unit-correlation-id',
        'method' => 'POST',
        'path' => '/v1/tenants/abc/domains',
    ]);
});

it('records a null correlation id when the header is absent', function () {
    // The global CorrelationId middleware guarantees the header on every
    // real request; a null here means the seam ran outside that pipeline.
    $handler = LogCapture::fake();

    app(PlatformRoleAudit::class)->recordRequest(Request::create('/v1/tenants', 'GET'));

    expect(LogCapture::entriesNamed($handler, PlatformRoleAudit::MESSAGE)[0]->context['correlation_id'])->toBeNull();
});
