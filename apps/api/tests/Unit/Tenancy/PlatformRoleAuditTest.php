<?php

use App\Models\User;
use App\Support\Tenancy\PlatformRoleAudit;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Http\Request;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 task breakdown item 15 upgrades PlatformRoleAudit's body from
 * a structured log line to a real activity_log row (App\Support\Audit\
 * ActivityLogger); recordRequest() itself does no SET LOCAL or
 * transaction work (ActivityLogger's own precedent), so every case here
 * wraps the call in the platform posture the real call site
 * (PlatformRequestTransaction) always provides.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

it('records exactly one activity_log entry under the stable event name and the sentinel tenant', function () {
    $request = Request::create('/v1/tenants', 'GET');
    $request->headers->set('X-Correlation-Id', 'unit-correlation-id');

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(PlatformRoleAudit::class)->recordRequest($request),
    );

    expect($entry->event)->toBe(PlatformRoleAudit::EVENT)
        ->and($entry->tenant_id)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->properties->get('platform_scope'))->toBeTrue();
});

it('carries the correlation id, method, and normalized path in the entry', function () {
    $request = Request::create('/v1/tenants/abc/domains?page=2', 'POST');
    $request->headers->set('X-Correlation-Id', 'unit-correlation-id');

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(PlatformRoleAudit::class)->recordRequest($request),
    );

    expect($entry->description)->toBe('POST /v1/tenants/abc/domains')
        ->and($entry->properties->get('correlation_id'))->toBe('unit-correlation-id');
});

it('records a null correlation id when the header is absent', function () {
    // The global CorrelationId middleware guarantees the header on every
    // real request; a null here means the seam ran outside that pipeline.
    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(PlatformRoleAudit::class)->recordRequest(Request::create('/v1/tenants', 'GET')),
    );

    expect($entry->properties->get('correlation_id'))->toBeNull();
});

it('attributes the entry to the authenticated staff bearer as causer', function () {
    $user = User::factory()->create();

    // Request::user($guard) resolves through the request's own user
    // resolver (Illuminate\Http\Request::getUserResolver()); swapping it
    // directly is a genuine unit-level way to prove recordRequest() reads
    // $request->user('staff') as its causer, without reconstructing a
    // real Passport-authenticated HTTP request.
    $request = Request::create('/v1/tenants', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(PlatformRoleAudit::class)->recordRequest($request),
    );

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->causer_type)->toBe(User::class);
});

it('records no causer when the request carries no authenticated staff user', function () {
    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => app(PlatformRoleAudit::class)->recordRequest(Request::create('/v1/tenants', 'GET')),
    );

    expect($entry->causer_id)->toBeNull()
        ->and($entry->causer_type)->toBeNull();
});
