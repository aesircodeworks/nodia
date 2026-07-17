<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Data\PayoutData;
use App\Payments\Exceptions\PayoutNotFoundException;
use App\Payments\Models\Payout;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The payout read surface (stage-08c plan, Endpoints "GET /v1/payouts"),
 * capability-gated on payouts.view, financially privileged so the admin
 * group's MFA enforcement applies. Payouts are a high-volume mirror of
 * gateway payout objects, so the list cursor-paginates; the plan's fixed
 * newest-first order (sort=-created_at, tie-broken by id) is not a
 * caller-adjustable sort, mirroring RefundController's fixed
 * descending-id precedent.
 */
class PayoutController
{
    /**
     * @return CursorPaginatedDataCollection<int, PayoutData>
     */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $payouts = QueryBuilder::for(Payout::class)
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('gateway'))
            ->allowedSorts()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return PayoutData::collect($payouts, CursorPaginatedDataCollection::class);
    }

    public function show(string $payout): PayoutData
    {
        $model = Payout::query()->find($payout) ?? throw PayoutNotFoundException::forId($payout);

        return PayoutData::fromModel($model);
    }
}
