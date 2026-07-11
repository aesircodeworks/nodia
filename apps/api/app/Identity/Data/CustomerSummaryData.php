<?php

namespace App\Identity\Data;

use App\Identity\Models\Customer;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One customer in the staff lookup list and the staff order detail's
 * customer summary (stage-07 plan, Endpoints "GET /v1/customers"):
 * identifiers and contact facts only, never credentials or tokens.
 */
#[MapName(SnakeCaseMapper::class)]
class CustomerSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $name,
        public string $createdAt,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            $customer->id,
            $customer->email,
            $customer->name,
            CarbonImmutable::instance($customer->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
