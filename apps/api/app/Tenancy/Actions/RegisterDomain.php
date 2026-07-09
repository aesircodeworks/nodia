<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Exceptions\InvalidDomainNameException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Spatie\LaravelData\Optional;

final class RegisterDomain
{
    public function __invoke(Tenant $tenant, RegisterTenantDomainData $data): TenantDomainData
    {
        $domain = mb_strtolower($data->domain);

        // filter_var accepts a trailing root dot, but "example.com." and
        // "example.com" would be two rows resolving the same Host header,
        // so the FQDN root notation is rejected rather than normalized.
        if (str_ends_with($domain, '.') || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw InvalidDomainNameException::for($data->domain);
        }

        $tenantDomain = TenantDomain::create([
            'tenant_id' => $tenant->id,
            'domain' => $domain,
            'is_primary' => $data->isPrimary instanceof Optional ? false : $data->isPrimary,
        ]);

        return TenantDomainData::fromModel($tenantDomain);
    }
}
