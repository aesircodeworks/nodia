<?php

namespace App\Tenancy\Data;

use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class TenantDomainData extends Data
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $domain,
        public bool $isPrimary,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(TenantDomain $domain): self
    {
        return new self(
            $domain->id,
            $domain->tenant_id,
            $domain->domain,
            $domain->is_primary,
            CarbonImmutable::instance($domain->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($domain->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
