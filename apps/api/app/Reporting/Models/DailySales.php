<?php

namespace App\Reporting\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Reporting\Models\DailySalesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One tenant, event, ticket type, and UTC calendar day's sales activity
 * (stage-11 plan, Data model "report_daily_sales"). A pre-aggregated
 * read model built by the ProjectDailySales outbox consumer (task 5),
 * not a source-of-truth table, so $table is set explicitly rather than
 * relying on Eloquent's guess from the class name (stage-11 plan, Data
 * model preamble). gross and refunded are both pre-discount face value
 * at issue time, not the finance projection's actual-charge figures
 * (stage-11 plan, Data model "report_daily_sales" money semantics
 * note); every mutation is an ON CONFLICT DO UPDATE additive increment
 * issued from the projector, never a bare Eloquent ->save() on this
 * model (master plan test-first rule 2, mirroring PurchaseCounter's own
 * posture).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $ticket_type_id
 * @property Carbon $sales_date
 * @property int $tickets_issued_count
 * @property int $tickets_refunded_count
 * @property Money $gross
 * @property Money $refunded
 * @property int $gross_amount
 * @property int $refunded_amount
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'ticket_type_id',
    'sales_date',
    'tickets_issued_count',
    'tickets_refunded_count',
    'gross',
    'refunded',
])]
class DailySales extends Model
{
    /** @use HasFactory<DailySalesFactory> */
    use HasFactory, HasUuids;

    protected $table = 'report_daily_sales';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sales_date' => 'date',
            'gross' => MoneyCast::class,
            'refunded' => MoneyCast::class,
        ];
    }
}
