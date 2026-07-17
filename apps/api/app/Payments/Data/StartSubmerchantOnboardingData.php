<?php

namespace App\Payments\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/submerchant-accounts (stage-08c plan, Endpoints). gateway
 * must be a registered adapter identifier; whether it exists and is
 * enabled for the tenant are business rules with their own stable
 * codes, checked in App\Payments\Actions\StartSubmerchantOnboarding,
 * never here (the CreateRefundData precedent).
 */
#[MapName(SnakeCaseMapper::class)]
class StartSubmerchantOnboardingData extends Data
{
    public function __construct(
        public string $gateway,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'gateway' => ['required', 'string'],
        ];
    }
}
