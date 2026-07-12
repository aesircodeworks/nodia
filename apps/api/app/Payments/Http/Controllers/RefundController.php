<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Actions\CreateRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Data\RefundData;
use App\Payments\Exceptions\IdempotencyKeyMissingException;
use App\Payments\Exceptions\RefundNotFoundException;
use App\Payments\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The staff refund surface (stage-08b plan, Endpoints): creation is
 * capability-gated on orders.refund, MFA-enforced by the admin group,
 * and activity-logged by RecordActivityAudit. Execution is
 * asynchronous; the 201 carries a pending refund.
 */
class RefundController
{
    public function store(
        Request $request,
        string $payment,
        CreateRefundData $data,
        CreateRefund $createRefund,
    ): JsonResponse {
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw IdempotencyKeyMissingException::make();
        }

        $result = $createRefund($payment, $data, $idempotencyKey);

        return response()->json(
            RefundData::fromModel($result->refund, $result->orderId),
            $result->replayed ? 200 : 201,
        );
    }

    /**
     * Refunds are potentially high-volume, so the list cursor-paginates
     * over descending id: ids are UUIDv7, so descending id is the
     * plan's newest-first default in one deterministic cursor column.
     * order_id filters and projects through the owning payment.
     */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $refunds = QueryBuilder::for(
            Refund::query()
                ->select('refunds.*', 'payments.order_id as order_id')
                ->join('payments', 'payments.id', '=', 'refunds.payment_id'),
        )
            ->allowedFilters(
                AllowedFilter::exact('payment_id', 'refunds.payment_id'),
                AllowedFilter::exact('status', 'refunds.status'),
                AllowedFilter::exact('order_id', 'payments.order_id'),
            )
            ->allowedSorts()
            ->orderByDesc('refunds.id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return RefundData::collect($refunds, CursorPaginatedDataCollection::class);
    }

    public function show(string $refund): RefundData
    {
        $model = Refund::query()->find($refund) ?? throw RefundNotFoundException::forId($refund);

        return RefundData::fromModel($model);
    }
}
