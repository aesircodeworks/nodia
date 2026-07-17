<?php

namespace App\Payments\Data;

use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;

/**
 * POST /v1/payments/{payment}/refunds (stage-08b plan, Endpoints). A
 * null amount means the full remaining refundable amount; ticket_ids is
 * the voiding selection persisted on the refund row at creation. The
 * currency and refundable-amount boundary checks live in
 * App\Payments\Actions\CreateRefund with their own stable codes, never
 * here (the CreateTicketTypeData precedent).
 */
#[MapName(SnakeCaseMapper::class)]
class CreateRefundData extends Data
{
    /**
     * @param  list<string>|null  $ticketIds
     */
    public function __construct(
        #[TypeScriptOptional]
        public ?Money $amount = null,
        #[TypeScriptOptional]
        public ?string $reason = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        public ?array $ticketIds = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['sometimes', 'nullable', 'array'],
            'amount.amount' => ['required_with:amount', 'integer', 'min:1'],
            'amount.currency' => ['required_with:amount', 'string', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_ids' => ['sometimes', 'nullable', 'array', 'list', 'min:1'],
            'ticket_ids.*' => ['uuid'],
        ];
    }
}
