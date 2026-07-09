<?php

use App\Tenancy\Events\DomainVerified;
use App\Tenancy\Events\DomainVerifiedPayload;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Str;

it('builds the tenant-scoped envelope from a tenant domain', function () {
    $tenantId = Str::uuid7()->toString();
    $domainId = Str::uuid7()->toString();
    $domain = TenantDomain::factory()->make([
        'id' => $domainId,
        'tenant_id' => $tenantId,
        'domain' => 'tickets.acme.com',
    ]);

    $event = DomainVerified::fromTenantDomain($domain);

    expect($event->tenantId)->toBe($tenantId)
        ->and($event->aggregateType)->toBe('tenant_domain')
        ->and($event->aggregateId)->toBe($domainId)
        ->and($event->payload)->toBeInstanceOf(DomainVerifiedPayload::class);
});

it('carries identifiers and facts in a snake_case payload', function () {
    $tenantId = Str::uuid7()->toString();
    $domainId = Str::uuid7()->toString();
    $domain = TenantDomain::factory()->make([
        'id' => $domainId,
        'tenant_id' => $tenantId,
        'domain' => 'tickets.acme.com',
    ]);

    $event = DomainVerified::fromTenantDomain($domain);

    expect($event->payload->toArray())->toBe([
        'tenant_domain_id' => $domainId,
        'tenant_id' => $tenantId,
        'domain' => 'tickets.acme.com',
    ]);
});
