<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

it('starts without a tenant and refuses to expose one', function () {
    $context = new TenantContext;

    expect($context->hasTenant())->toBeFalse()
        ->and($context->isPlatform())->toBeFalse()
        ->and(fn () => $context->tenantId())->toThrow(LogicException::class);
});

it('exposes the tenant id and posture while entered', function () {
    $tenantId = Str::uuid7()->toString();
    $context = new TenantContext;

    $context->enter($tenantId, Rls::APP_ROLE);

    expect($context->hasTenant())->toBeTrue()
        ->and($context->tenantId())->toBe($tenantId)
        ->and($context->isPlatform())->toBeFalse();
});

it('reports the platform posture when entered with the platform role', function () {
    $context = new TenantContext;

    $context->enter(config()->string('tenancy.platform_tenant_id'), Rls::PLATFORM_ROLE);

    expect($context->isPlatform())->toBeTrue();
});

it('clears back to the empty state', function () {
    $context = new TenantContext;
    $context->enter(Str::uuid7()->toString(), Rls::PLATFORM_ROLE);

    $context->clear();

    expect($context->hasTenant())->toBeFalse()
        ->and($context->isPlatform())->toBeFalse()
        ->and(fn () => $context->tenantId())->toThrow(LogicException::class);
});

it('resolves as one instance per request scope from the container', function () {
    $first = app(TenantContext::class);
    $second = app(TenantContext::class);

    expect($second)->toBe($first);

    $first->enter(Str::uuid7()->toString(), Rls::APP_ROLE);
    app()->forgetScopedInstances();

    $fresh = app(TenantContext::class);

    expect($fresh)->not->toBe($first)
        ->and($fresh->hasTenant())->toBeFalse();
});

it('publishes the fixed sentinel platform tenant id through config', function () {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    expect(Str::isUuid($sentinel))->toBeTrue();
});
