<?php

namespace App\Tenancy\Actions;

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\TenantDomain;

/**
 * Answers "which tenant owns this host" before any tenant context exists,
 * under the narrow nodia_resolver posture rather than the audited
 * platform role (system-design 4.3; stage-02 domain-resolution decision).
 */
final readonly class ResolveDomain
{
    public function __construct(private TenantTransaction $transaction) {}

    public function tenantIdFor(string $host): ?string
    {
        $domain = self::normalizeHost($host);

        if ($domain === '') {
            return null;
        }

        return $this->transaction->asDomainResolver(
            fn (): ?string => TenantDomain::query()->where('domain', $domain)->value('tenant_id'),
        );
    }

    /**
     * Host headers arrive with arbitrary case and an optional port;
     * tenant_domains stores lowercase hostnames without ports. A bracketed
     * IPv6 literal keeps its brackets (it can never match a registered
     * domain, so the lookup simply misses).
     */
    public static function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));

        $portless = str_starts_with($host, '[')
            ? preg_replace('/\]:\d+$/', ']', $host)
            : preg_replace('/:\d+$/', '', $host);

        return $portless ?? $host;
    }
}
