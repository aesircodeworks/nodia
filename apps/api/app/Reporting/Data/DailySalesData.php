<?php

namespace App\Reporting\Data;

use App\Reporting\Models\DailySales;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\Hidden as WireHidden;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One report_daily_sales row's wire shape (stage-11 plan, Endpoints
 * "DailySalesData"): event_id, ticket_type_id, sales_date,
 * tickets_issued_count, tickets_refunded_count, gross, refunded. gross
 * and refunded are pre-discount face value at issue time (stage-11 plan,
 * Data model "report_daily_sales" money semantics note), not the finance
 * projection's actual-charge figures.
 *
 * No id property on the wire: the row's natural key is event_id plus
 * ticket_type_id plus sales_date (the table's own unique constraint),
 * and there is no GET-by-id endpoint for this projection, unlike every
 * other paginated resource in this codebase. id is still declared here,
 * hidden from both the transformed array and the generated TypeScript
 * type via the two Hidden attributes below, because it is technically
 * required: the cursor pagination envelope's deterministic order is
 * (sales_date, id) (stage-11 plan, Endpoints), and
 * Illuminate\Pagination\AbstractCursorPaginator::getParametersForItem()
 * reads the next/previous cursor's column values off the *transformed*
 * paginator items (Spatie\LaravelData\CursorPaginatedDataCollection maps
 * every item to this Data class before the paginator ever renders its
 * meta), by plain PHP property access matching each orderBy column name
 * verbatim. Without a real `id` property here the second cursor column
 * would silently resolve to null and pagination would misbehave once two
 * rows share the same sales_date. sales_date itself is deliberately
 * snake_case in PHP, not the usual camelCase salesDate, for the same
 * reason: it must match the literal `sales_date` orderBy column name,
 * mirroring App\Payments\Data\LedgerEntryData's own created_at precedent.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class DailySalesData extends Data
{
    public function __construct(
        #[WireHidden]
        #[TypeScriptHidden]
        public string $id,
        public string $eventId,
        public string $ticketTypeId,
        public string $sales_date,
        public int $ticketsIssuedCount,
        public int $ticketsRefundedCount,
        public Money $gross,
        public Money $refunded,
    ) {}

    public static function fromModel(DailySales $row): self
    {
        return new self(
            $row->id,
            $row->event_id,
            $row->ticket_type_id,
            $row->sales_date->toDateString(),
            $row->tickets_issued_count,
            $row->tickets_refunded_count,
            $row->gross,
            $row->refunded,
        );
    }
}
