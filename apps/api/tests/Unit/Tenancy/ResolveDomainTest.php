<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ResolveDomain;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
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

it('normalizes the host before lookup', function (string $host, string $normalized) {
    expect(ResolveDomain::normalizeHost($host))->toBe($normalized);
})->with([
    'already normalized' => ['tickets.acme.com', 'tickets.acme.com'],
    'mixed case lowered' => ['Tickets.ACME.Com', 'tickets.acme.com'],
    'port stripped' => ['tickets.acme.com:8443', 'tickets.acme.com'],
    'mixed case and port' => ['Tickets.ACME.Com:443', 'tickets.acme.com'],
    'surrounding whitespace trimmed' => ["  tickets.acme.com\t", 'tickets.acme.com'],
    'bracketed ipv6 keeps brackets, loses port' => ['[2001:DB8::1]:8000', '[2001:db8::1]'],
    'single label untouched' => ['localhost', 'localhost'],
]);

it('resolves a registered domain to its tenant id regardless of case and port', function (string $host) {
    app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::factory()->create([
        'tenant_id' => $this->tenant->id,
        'domain' => 'tickets.acme.com',
    ]));

    expect(app(ResolveDomain::class)->tenantIdFor($host))->toBe($this->tenant->id);
})->with([
    'exact' => 'tickets.acme.com',
    'mixed case' => 'Tickets.ACME.Com',
    'with port' => 'tickets.acme.com:8443',
]);

it('returns null for an unregistered host', function () {
    expect(app(ResolveDomain::class)->tenantIdFor('unknown.example'))->toBeNull();
});

it('returns null for an empty host without touching the database', function () {
    DB::enableQueryLog();

    expect(app(ResolveDomain::class)->tenantIdFor(''))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});
