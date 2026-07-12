<?php

namespace App\Payments\Data;

use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The submerchant account wire shape (stage-08c plan, Endpoints).
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class SubmerchantAccountData extends Data
{
    /**
     * @param  list<string>  $requirements
     */
    public function __construct(
        public string $id,
        public string $gateway,
        public SubmerchantStatus $status,
        public ?string $gatewayAccountReference,
        public ?string $onboardingUrl,
        public array $requirements,
        public ?string $activatedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SubmerchantAccount $account): self
    {
        return new self(
            $account->id,
            $account->gateway,
            $account->status,
            $account->gateway_account_reference,
            $account->onboarding_url,
            $account->requirements,
            $account->activated_at === null ? null : CarbonImmutable::instance($account->activated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($account->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($account->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
