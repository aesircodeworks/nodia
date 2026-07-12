<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Data\LedgerBalanceData;
use App\Payments\Data\LedgerEntryData;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Payments\Models\LedgerEntry;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The raw finance reads (stage-08b plan, Endpoints): ledger entries are
 * high-volume, so the list cursor-paginates (api-conventions names
 * them explicitly) over ascending id, which for UUIDv7 keys is the
 * plan's deterministic (created_at, id) chronology in one cursor
 * column. Balances are a bounded per-currency, per-account summary
 * computed in SQL from the same table.
 */
class LedgerController
{
    public function entries(Request $request): CursorPaginatedDataCollection
    {
        $entries = QueryBuilder::for(LedgerEntry::class)
            ->allowedFilters(
                AllowedFilter::exact('account'),
                AllowedFilter::exact('reference_type'),
                AllowedFilter::exact('reference_id'),
                AllowedFilter::callback('created_at_from', fn (Builder $query, mixed $value) => $query->where('created_at', '>=', (string) $value)),
                AllowedFilter::callback('created_at_to', fn (Builder $query, mixed $value) => $query->where('created_at', '<=', (string) $value)),
            )
            ->allowedSorts()
            ->orderBy('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return LedgerEntryData::collect($entries, CursorPaginatedDataCollection::class);
    }

    /**
     * @return array{data: list<LedgerBalanceData>}
     */
    public function balances(): array
    {
        $rows = LedgerEntry::query()
            ->toBase()
            ->select('currency', 'account')
            ->selectRaw(
                'sum(case when direction = ? then amount else -amount end) as credit_balance',
                [LedgerDirection::Credit->value],
            )
            ->groupBy('currency', 'account')
            ->orderBy('currency')
            ->orderBy('account')
            ->get();

        $balances = $rows->map(function (object $row): LedgerBalanceData {
            $account = LedgerAccount::from((string) $row->account);

            // Signed by convention: debit-positive for the receivable,
            // credit-positive everywhere else.
            $signed = $account === LedgerAccount::GatewayReceivable
                ? -(int) $row->credit_balance
                : (int) $row->credit_balance;

            return new LedgerBalanceData((string) $row->currency, $account, Money::of($signed, (string) $row->currency));
        })->all();

        return ['data' => $balances];
    }
}
