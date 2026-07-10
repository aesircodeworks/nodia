<?php

namespace App\Tenancy\Actions;

use App\Support\Database\Rls;
use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Events\DomainVerified;
use App\Tenancy\Exceptions\DomainAlreadyRegisteredException;
use App\Tenancy\Exceptions\InvalidDomainNameException;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/tenants/{tenant}/domains (stage-02 plan). The whole platform
 * request already runs inside one database transaction under nodia_platform
 * (PlatformRequestTransaction), so this Action needs no transaction of its
 * own. DomainVerified fires on registration (Stage 2 settled trigger) and
 * is recorded into the outbox in that same transaction (stage-04 plan,
 * Slice 6). The envelope carries the owning tenant; outbox_events has no
 * platform write policy, so the record path switches the open transaction
 * to nodia_app with app.tenant_id set to the owning tenant before insert.
 */
final class RegisterDomain
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

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

        // DomainVerified envelope tenant_id is the owning tenant; outbox
        // tables have no platform write policy, so the insert must run under
        // nodia_app with app.tenant_id matching the envelope. SET LOCAL dies
        // with the enclosing platform request transaction.
        DB::statement('set local role '.Rls::APP_ROLE);
        DB::selectOne('select set_config(?, ?, true)', ['app.tenant_id', $tenant->id]);

        $this->outbox->record(DomainVerified::fromTenantDomain($tenantDomain));

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
