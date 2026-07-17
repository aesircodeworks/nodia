<?php

namespace App\Reporting\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Reporting\Models\EventFinanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One tenant and event's finance totals (stage-11 plan, Data model
 * "report_event_finance"), mirroring the ledger's own per-event accounts
 * (system-design 7.3). A pre-aggregated read model built by the
 * ProjectEventFinance outbox consumer (task 8), not a source-of-truth
 * table, so $table is set explicitly rather than relying on Eloquent's
 * guess. gross, platform_commission, and tenant_net are all netted by
 * refunds; refunded is a separate face-value counter (see the money
 * semantics note on the report_event_finance migration). Every mutation
 * is an ON CONFLICT DO UPDATE additive increment issued from the
 * projector, never a bare Eloquent ->save() on this model (master plan
 * test-first rule 2, mirroring DailySales's own posture).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property int $orders_paid_count
 * @property int $refunds_count
 * @property Money $gross
 * @property Money $gateway_fee
 * @property Money $platform_commission
 * @property Money $tenant_net
 * @property Money $refunded
 * @property int $gross_amount
 * @property int $gateway_fee_amount
 * @property int $platform_commission_amount
 * @property int $tenant_net_amount
 * @property int $refunded_amount
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'orders_paid_count',
    'refunds_count',
    'gross',
    'gateway_fee',
    'platform_commission',
    'tenant_net',
    'refunded',
])]
class EventFinance extends Model
{
    /** @use HasFactory<EventFinanceFactory> */
    use HasFactory, HasUuids;

    protected $table = 'report_event_finance';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross' => MoneyCast::class,
            'gateway_fee' => MoneyCast::class,
            'platform_commission' => MoneyCast::class,
            'tenant_net' => MoneyCast::class,
            'refunded' => MoneyCast::class,
        ];
    }
}
