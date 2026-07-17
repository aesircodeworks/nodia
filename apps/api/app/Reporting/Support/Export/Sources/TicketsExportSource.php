<?php

namespace App\Reporting\Support\Export\Sources;

use App\Orders\Actions\PaginateTicketsForExport;
use App\Orders\Data\TicketExportRowData;
use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\ExportSource;

/**
 * The `tickets` export (stage-11 plan, task 15/T12). Every row comes
 * from App\Orders\Actions\PaginateTicketsForExport, never from an
 * App\Orders\Models\Ticket query in this file, so Reporting never
 * touches the tickets table directly.
 */
final readonly class TicketsExportSource implements ExportSource
{
    public function __construct(private PaginateTicketsForExport $paginate) {}

    public function type(): ExportType
    {
        return ExportType::Tickets;
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
     * $tenantId goes unused: RLS already scopes Ticket::query() to the
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
            'id' => static fn (TicketExportRowData $row): string => $row->id,
            'event_id' => static fn (TicketExportRowData $row): string => $row->eventId,
            'ticket_type_id' => static fn (TicketExportRowData $row): string => $row->ticketTypeId,
            'order_id' => static fn (TicketExportRowData $row): string => $row->orderId,
            'status' => static fn (TicketExportRowData $row): string => $row->status,
            'attendee_name' => static fn (TicketExportRowData $row): ?string => $row->attendeeName,
            'list_price_amount' => static fn (TicketExportRowData $row): ?int => $row->listPrice?->amount,
            'list_price_currency' => static fn (TicketExportRowData $row): ?string => $row->listPrice?->currency,
            'issued_at' => static fn (TicketExportRowData $row): string => $row->issuedAt,
        ];
    }
}
