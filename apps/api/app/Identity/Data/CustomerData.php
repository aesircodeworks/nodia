<?php

namespace App\Identity\Data;

use App\Identity\Models\Customer;
use RuntimeException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CustomerData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public string $locale,
        public bool $isClaimed,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self(
            $customer->id,
            $customer->email,
            $customer->name,
            // RegisterCustomer always resolves a concrete locale (falling
            // back to the tenant default) before creating the row, so a
            // null column here would be a genuine bug, not a valid state
            // to pass along silently.
            $customer->locale ?? throw new RuntimeException('Customer has no locale set.'),
            $customer->password !== null,
        );
    }
}
