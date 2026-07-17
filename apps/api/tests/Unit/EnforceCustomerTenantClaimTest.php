<?php

use App\Http\Middleware\EnforceCustomerTenantClaim;
use App\Identity\Exceptions\TenantMismatchException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/*
 * Stage-03 plan, task breakdown item 13: drives the middleware directly
 * with a fabricated bearer token, mirroring
 * tests/Unit/RequireCapabilityTest.php's own precedent of exercising a
 * shared cross-context middleware without the full HTTP pipeline. The
 * middleware never verifies the token's signature (its own docblock), so
 * a lightweight symmetric-signer JWT is enough to prove the claim
 * comparison in isolation.
 */

function tenantClaimToken(?string $tenantId): string
{
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(str_repeat('a', 32)));

    $builder = $config->builder()->identifiedBy('test-token');

    if ($tenantId !== null) {
        $builder = $builder->withClaim('tenant_id', $tenantId);
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

function contextFor(string $tenantId): TenantContext
{
    $context = new TenantContext;
    $context->enter($tenantId, 'nodia_app');

    return $context;
}

it('calls the next handler when there is no bearer token', function (): void {
    $middleware = new EnforceCustomerTenantClaim(contextFor('tenant-a'));

    $response = $middleware->handle(Request::create('/v1/customers'), fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(204);
});

it('calls the next handler when the bearer carries no tenant_id claim', function (): void {
    $middleware = new EnforceCustomerTenantClaim(contextFor('tenant-a'));
    $request = Request::create('/v1/auth/customer/logout');
    $request->headers->set('Authorization', 'Bearer '.tenantClaimToken(null));

    $response = $middleware->handle($request, fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(204);
});

it('calls the next handler when the tenant_id claim matches the asserted tenant', function (): void {
    $middleware = new EnforceCustomerTenantClaim(contextFor('tenant-a'));
    $request = Request::create('/v1/auth/customer/logout');
    $request->headers->set('Authorization', 'Bearer '.tenantClaimToken('tenant-a'));

    $response = $middleware->handle($request, fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(204);
});

it('throws TenantMismatchException when the tenant_id claim does not match the asserted tenant', function (): void {
    $middleware = new EnforceCustomerTenantClaim(contextFor('tenant-a'));
    $request = Request::create('/v1/auth/customer/logout');
    $request->headers->set('Authorization', 'Bearer '.tenantClaimToken('tenant-b'));

    expect(fn () => $middleware->handle($request, fn () => response()->noContent()))
        ->toThrow(TenantMismatchException::class);
});

it('calls the next handler for an unparseable bearer token, leaving rejection to auth:customer', function (): void {
    $middleware = new EnforceCustomerTenantClaim(contextFor('tenant-a'));
    $request = Request::create('/v1/auth/customer/logout');
    $request->headers->set('Authorization', 'Bearer not-a-real-token');

    $response = $middleware->handle($request, fn () => response()->noContent());

    expect($response->getStatusCode())->toBe(204);
});
