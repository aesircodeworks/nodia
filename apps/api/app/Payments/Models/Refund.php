<?php

namespace App\Payments\Models;

use App\Payments\Enums\RefundStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Payments\Models\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One refund against a confirmed payment (system-design 8.3 REFUND,
 * stage-08b plan Data model "refunds"). The row is a single monetary
 * fact, so the principal value is bare amount paired with currency;
 * commission_amount is the returned commission derived at creation and
 * kept as a row fact for deterministic ledger replay. ticket_ids is the
 * request's selection persisted so the asynchronous completion
 * transaction and idempotency replays read it from the row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property Money $money
 * @property int $amount
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $reason
 * @property list<string>|null $ticket_ids
 * @property int $commission_amount
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string|null $gateway_reference
 * @property string|null $failure_code
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'payment_id',
    'money',
    'status',
    'reason',
    'ticket_ids',
    'commission_amount',
    'idempotency_key',
    'request_hash',
    'gateway_reference',
    'failure_code',
])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'money' => MoneyCast::class.':amount',
            'ticket_ids' => 'array',
        ];
    }
}
