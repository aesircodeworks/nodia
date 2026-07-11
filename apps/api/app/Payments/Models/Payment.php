<?php

namespace App\Payments\Models;

use App\Payments\Enums\PaymentStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Payments\Models\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One payment attempt against an order (system-design 8.3). The row is a
 * single monetary fact, so the principal value is bare amount paired
 * with currency (data-conventions Money exception); fee_amount and
 * commission_amount are persisted by ConfirmPayment on the confirm
 * transition. order_id is a cross-context reference with no Eloquent
 * relation into Orders' model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $gateway
 * @property string $method
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string|null $gateway_reference
 * @property Money $money
 * @property int $amount
 * @property string $currency
 * @property int $fee_amount
 * @property int $commission_amount
 * @property PaymentStatus $status
 * @property string|null $failure_code
 * @property array<string, mixed>|null $next_action
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'order_id',
    'gateway',
    'method',
    'idempotency_key',
    'request_hash',
    'gateway_reference',
    'money',
    'status',
    'failure_code',
    'next_action',
    'expires_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'money' => MoneyCast::class.':amount',
            'next_action' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
