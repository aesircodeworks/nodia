<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\RegisterDomain;
use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Exceptions\DomainAlreadyRegisteredException;
use App\Tenancy\Exceptions\InvalidDomainNameException;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

it('lowercases the domain before persisting', function () {
    $data = RegisterTenantDomainData::from(['domain' => 'Tickets.Acme.COM']);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(RegisterDomain::class)($this->tenant, $data));

    expect($result)->toBeInstanceOf(TenantDomainData::class)
        ->and($result->domain)->toBe('tickets.acme.com')
        ->and($result->tenantId)->toBe($this->tenant->id)
        ->and($result->isPrimary)->toBeFalse();

    $stored = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->find($result->id));

    expect($stored)->not->toBeNull()
        ->and($stored->domain)->toBe('tickets.acme.com')
        ->and($stored->is_primary)->toBeFalse();
});

it('honors an explicit is_primary flag', function () {
    $data = RegisterTenantDomainData::from(['domain' => 'tickets.acme.com', 'is_primary' => true]);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(RegisterDomain::class)($this->tenant, $data));

    expect($result->isPrimary)->toBeTrue();

    $stored = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->find($result->id));

    expect($stored->is_primary)->toBeTrue();
});

it('rejects malformed hostnames', function (string $domain) {
    $data = RegisterTenantDomainData::from(['domain' => $domain]);

    expect(fn () => app(TenantTransaction::class)->asPlatform(fn () => app(RegisterDomain::class)($this->tenant, $data)))
        ->toThrow(InvalidDomainNameException::class);

    $count = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->count());

    expect($count)->toBe(0);
})->with([
    'empty string' => [''],
    'embedded space' => ['exa mple.com'],
    'label starting with a hyphen' => ['-bad.example.com'],
    'label ending with a hyphen' => ['bad-.example.com'],
    'empty label' => ['example..com'],
    'scheme prefix' => ['https://example.com'],
    'path suffix' => ['example.com/path'],
    'port suffix' => ['example.com:8080'],
    'trailing dot' => ['example.com.'],
    'label longer than 63 characters' => [str_repeat('a', 64).'.com'],
    'non-ascii label' => ['exämple.com'],
    'wildcard label' => ['*.acme.com'],
    'underscore label' => ['_dmarc.example.com'],
]);

it('serializes TenantDomainData with snake_case keys and ISO 8601 UTC timestamps', function () {
    $data = RegisterTenantDomainData::from(['domain' => 'tickets.acme.com']);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(RegisterDomain::class)($this->tenant, $data));

    $wire = $result->toArray();

    expect(array_keys($wire))->toBe([
        'id',
        'tenant_id',
        'domain',
        'is_primary',
        'created_at',
        'updated_at',
    ])
        ->and($wire['is_primary'])->toBeFalse()
        ->and($wire['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($wire['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('maps a duplicate domain to DomainAlreadyRegisteredException, even across case', function () {
    app(TenantTransaction::class)->asPlatform(
        fn () => app(RegisterDomain::class)($this->tenant, RegisterTenantDomainData::from(['domain' => 'taken.acme.com'])),
    );

    $other = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());

    expect(fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(RegisterDomain::class)($other, RegisterTenantDomainData::from(['domain' => 'Taken.Acme.COM'])),
    ))->toThrow(DomainAlreadyRegisteredException::class);

    $count = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('domain', 'taken.acme.com')->count(),
    );

    expect($count)->toBe(1);
});

it('maps a second primary registration to TenantDomainIsPrimaryException via the partial unique index', function () {
    app(TenantTransaction::class)->asPlatform(
        fn () => app(RegisterDomain::class)($this->tenant, RegisterTenantDomainData::from(['domain' => 'first.acme.com', 'is_primary' => true])),
    );

    expect(fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(RegisterDomain::class)($this->tenant, RegisterTenantDomainData::from(['domain' => 'second.acme.com', 'is_primary' => true])),
    ))->toThrow(TenantDomainIsPrimaryException::class);

    $primaries = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $this->tenant->id)->where('is_primary', true)->count(),
    );

    expect($primaries)->toBe(1);
});
