<?php

namespace App\Orders\Http\Controllers;

use App\Identity\Actions\ResolveCustomerSummary;
use App\Orders\Actions\ResendTickets;
use App\Orders\Data\OrderData;
use App\Orders\Data\OrderDetailData;
use App\Orders\Data\TicketData;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Orders\Support\TicketQrCodec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The staff order surface (stage-07 plan, Endpoints "Staff-facing").
 * Orders are high-volume, so the list cursor-paginates
 * (api-conventions) over id desc: ids are UUIDv7, so descending id is
 * descending creation time, and the cursor paginator needs its order
 * columns present on the transformed OrderData items when deriving the
 * next cursor. Allowed query-builder parameters are filter[status],
 * filter[event_id], filter[customer_id] (exact) and
 * filter[created_from] / filter[created_to] (created_at range); no
 * sorts are allowed, so unknown ones are rejected with 400
 * invalid_query_parameter.
 */
class StaffOrderController
{
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $orders = QueryBuilder::for(Order::query()->with('items'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('event_id'),
                AllowedFilter::exact('customer_id'),
                AllowedFilter::callback('created_from', fn (Builder $query, mixed $value) => $query->where('created_at', '>=', (string) $value)),
                AllowedFilter::callback('created_to', fn (Builder $query, mixed $value) => $query->where('created_at', '<=', (string) $value)),
            )
            ->allowedSorts()
            ->orderByDesc('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return OrderData::collect($orders, CursorPaginatedDataCollection::class);
    }

    public function show(string $order, ResolveCustomerSummary $resolveCustomer, TicketQrCodec $codec): OrderDetailData
    {
        $model = $this->orderOrFail($order)->load('items');

        $tickets = Ticket::query()
            ->where('order_id', $model->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): TicketData => TicketData::fromModel($ticket, $codec->sign($ticket)))
            ->all();

        return OrderDetailData::fromModel($model, $tickets, $resolveCustomer($model->customer_id));
    }

    /**
     * 202: the resend pathway is a dormant seam until Stage 8a's email
     * and PDF consumers exist; the qr_rotation_counter bump ships with
     * that activation task (stage-07 plan, Risks).
     */
    public function resendTickets(string $order, ResendTickets $resendTickets): Response
    {
        $resendTickets($this->orderOrFail($order)->id);

        return response()->noContent(202);
    }

    private function orderOrFail(string $orderId): Order
    {
        return Order::query()->find($orderId) ?? throw OrderNotFoundException::forId($orderId);
    }
}
