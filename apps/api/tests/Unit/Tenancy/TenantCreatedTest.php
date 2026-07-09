<?php

use App\Tenancy\Events\TenantCreated;
use App\Tenancy\Events\TenantCreatedPayload;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

it('builds the platform-scope envelope from a created tenant', function () {
    $id = Str::uuid7()->toString();
    $tenant = Tenant::factory()->make([
        'id' => $id,
        'name' => 'Acme Tickets',
        'default_locale' => 'pt',
        'supported_locales' => ['pt', 'en'],
    ]);

    $event = TenantCreated::fromTenant($tenant);

    expect($event->tenantId)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($event->aggregateType)->toBe('tenant')
        ->and($event->aggregateId)->toBe($id)
        ->and($event->payload)->toBeInstanceOf(TenantCreatedPayload::class);
});

it('carries identifiers and facts in a snake_case payload', function () {
    $id = Str::uuid7()->toString();
    $tenant = Tenant::factory()->make([
        'id' => $id,
        'name' => 'Acme Tickets',
        'default_locale' => 'pt',
        'supported_locales' => ['pt', 'en'],
    ]);

    $event = TenantCreated::fromTenant($tenant);

    expect($event->payload->toArray())->toBe([
        'tenant_id' => $id,
        'name' => 'Acme Tickets',
        'default_locale' => 'pt',
    ]);
});
