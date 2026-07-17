<?php

namespace App\Payments\Models;

use App\Payments\Enums\PayoutStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Payments\Models\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A mirror of one gateway payout object (system-design 7.3, 8.3;
 * stage-08c plan, Data model "payouts"). The row is a single monetary
 * fact, so the principal value is bare amount paired with currency
 * (data-conventions Money exception). Created by webhook ingestion or the
 * reconciliation poller, never by an admin request; no ledger columns
 * live here, the ledger remains the source of truth for balances.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string $gateway_reference
 * @property Money $money
 * @property int $amount
 * @property string $currency
 * @property PayoutStatus $status
 * @property Carbon|null $executed_at
 * @property Carbon|null $reconciled_at
 * @property int|null $discrepancy_amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'gateway',
    'gateway_reference',
    'money',
    'status',
    'executed_at',
    'reconciled_at',
    'discrepancy_amount',
])]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'money' => MoneyCast::class.':amount',
            'executed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
