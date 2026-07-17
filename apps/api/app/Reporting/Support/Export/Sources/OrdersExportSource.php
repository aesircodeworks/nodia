<?php

namespace App\Reporting\Support\Export\Sources;

use App\Orders\Actions\PaginateOrdersForExport;
use App\Orders\Data\OrderExportRowData;
use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\ExportSource;

/**
 * The `orders` export (stage-11 plan, task 15). Every row comes from
 * App\Orders\Actions\PaginateOrdersForExport, never from an
 * App\Orders\Models\Order query in this file, so Reporting never touches
 * the orders tables directly.
 */
final readonly class OrdersExportSource implements ExportSource
{
    public function __construct(private PaginateOrdersForExport $paginate) {}

    public function type(): ExportType
    {
        return ExportType::Orders;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * $tenantId goes unused: RLS already scopes Order::query() to the
     * tenant transaction App\Reporting\Jobs\BuildExportJob opened before
     * this method is ever called (see ExportSource's own docblock).
     */
    public function pages(string $tenantId, array $parameters): iterable
    {
        return ($this->paginate)(
            $parameters['event_id'] ?? null,
            $parameters['from'] ?? null,
            $parameters['to'] ?? null,
        );
    }

    public function columns(): array
    {
        return [
            'id' => static fn (OrderExportRowData $row): string => $row->id,
            'event_id' => static fn (OrderExportRowData $row): string => $row->eventId,
            'status' => static fn (OrderExportRowData $row): string => $row->status,
            'subtotal_amount' => static fn (OrderExportRowData $row): int => $row->subtotal->amount,
            'subtotal_currency' => static fn (OrderExportRowData $row): string => $row->subtotal->currency,
            'discount_amount' => static fn (OrderExportRowData $row): int => $row->discount->amount,
            'discount_currency' => static fn (OrderExportRowData $row): string => $row->discount->currency,
            'fees_amount' => static fn (OrderExportRowData $row): int => $row->fees->amount,
            'fees_currency' => static fn (OrderExportRowData $row): string => $row->fees->currency,
            'total_amount' => static fn (OrderExportRowData $row): int => $row->total->amount,
            'total_currency' => static fn (OrderExportRowData $row): string => $row->total->currency,
            'created_at' => static fn (OrderExportRowData $row): string => $row->createdAt,
        ];
    }
}
