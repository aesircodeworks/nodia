<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Exceptions\DomainAlreadyRegisteredException;
use App\Tenancy\Exceptions\InvalidDomainNameException;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\LaravelData\Optional;

final class RegisterDomain
{
    public function __invoke(Tenant $tenant, RegisterTenantDomainData $data): TenantDomainData
    {
        $domain = mb_strtolower($data->domain);

        if (! self::isValidHostname($domain)) {
            throw InvalidDomainNameException::for($data->domain);
        }

        // The unique indexes are the invariant guards, never a
        // read-then-write existence check: the database decides who wins a
        // concurrent registration, and the loser's violation is translated
        // here by constraint name.
        try {
            $tenantDomain = TenantDomain::create([
                'tenant_id' => $tenant->id,
                'domain' => $domain,
                'is_primary' => $data->isPrimary instanceof Optional ? false : $data->isPrimary,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'tenant_domains_primary_per_tenant_idx')) {
                throw TenantDomainIsPrimaryException::forExistingPrimary($domain);
            }

            if (str_contains($e->getMessage(), 'tenant_domains_domain_unique')) {
                throw DomainAlreadyRegisteredException::for($domain);
            }

            throw $e;
        }

        return TenantDomainData::fromModel($tenantDomain);
    }

    public static function isValidHostname(string $domain): bool
    {
        // filter_var accepts a trailing root dot, but "example.com." and
        // "example.com" would be two rows resolving the same Host header,
        // so the FQDN root notation is rejected rather than normalized.
        return ! str_ends_with($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
