<?php

namespace App\Payments\Models;

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One leg of a balanced double-entry set (system-design 7.3, 8.3). The
 * table is append-only at the database level: an UPDATE or DELETE
 * raises in the trigger shipped with the creating migration, so
 * corrections are new entries. The (source_event_id, account) unique
 * index is the idempotence anchor: one outbox event produces at most
 * one entry per account, making duplicate delivery and replay no-ops.
 *
 * @property string $id
 * @property string $tenant_id
 * @property LedgerAccount $account
 * @property LedgerDirection $direction
 * @property Money $money
 * @property int $amount
 * @property string $currency
 * @property string $reference_type
 * @property string $reference_id
 * @property string $source_event_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'account',
    'direction',
    'money',
    'amount',
    'currency',
    'reference_type',
    'reference_id',
    'source_event_id',
])]
class LedgerEntry extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'direction' => LedgerDirection::class,
            'money' => MoneyCast::class.':amount',
        ];
    }
}
