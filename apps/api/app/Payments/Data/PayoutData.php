<?php

namespace App\Payments\Data;

use App\Payments\Enums\PayoutStatus;
use App\Payments\Models\Payout;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The payout wire shape (stage-08c plan, Endpoints "GET /v1/payouts").
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class PayoutData extends Data
{
    public function __construct(
        public string $id,
        public string $gateway,
        public string $gatewayReference,
        public Money $amount,
        public PayoutStatus $status,
        public ?string $executedAt,
        public ?string $reconciledAt,
        public ?Money $discrepancy,
        // Snake-cased to match the (created_at, id) cursor columns: the
        // transformed paginator reads the ordering column off this DTO.
        public string $created_at,
    ) {}

    public static function fromModel(Payout $payout): self
    {
        return new self(
            $payout->id,
            $payout->gateway,
            $payout->gateway_reference,
            $payout->money,
            $payout->status,
            $payout->executed_at === null ? null : CarbonImmutable::instance($payout->executed_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $payout->reconciled_at === null ? null : CarbonImmutable::instance($payout->reconciled_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $payout->discrepancy_amount === null ? null : Money::of($payout->discrepancy_amount, $payout->currency),
            CarbonImmutable::instance($payout->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
