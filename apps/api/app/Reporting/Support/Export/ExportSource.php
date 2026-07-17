<?php

namespace App\Reporting\Support\Export;

use App\Reporting\Enums\ExportType;

/**
 * The seam every export type registers behind (stage-11 plan, Scope:
 * "BuildExport producing CSV files from cursor-paginated sources exposed
 * as Actions by the owning contexts"; task 15). Reporting never queries
 * another context's tables directly, so a source's pages() method always
 * delegates to an Action the owning context exposes; the source itself
 * carries no SQL of its own beyond its own Reporting-owned tables (none
 * of the four export types read a Reporting table, so in practice none
 * do).
 *
 * columns() and pages() are deliberately separate: pages() yields
 * whatever row shape the owning context's Action already returns (plain
 * objects, never wire Data classes, mirroring
 * App\Orders\Data\TicketSaleFactsData's own never-serialized posture),
 * and columns() is the CSV projection of that shape, an ordered map of
 * header to a per-row value extractor. Splitting them keeps a money
 * field's row-level type (App\Support\Money\Money) intact for as long as
 * possible; only the column extractor decomposes it into the two
 * required CSV columns, `*_amount` (int, minor units) and `*_currency`
 * (string), never a formatted decimal (ADR 018; data-conventions).
 */
interface ExportSource
{
    public function type(): ExportType;

    /**
     * Laravel validation rules for the export's `parameters` bag
     * (stage-11 plan, Data model "exports"). T13's POST /v1/exports
     * validates the request's parameters against the registered source
     * for the requested type, not a single rule set shared by every
     * type.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array;

    /**
     * One page at a time, never the whole result set: the caller (the
     * CSV writer) must be able to write a page's rows and let PHP
     * release them before the next page is even constructed. $tenantId
     * is carried for sources whose own Action needs it explicitly, but
     * every current source relies on RLS already scoping the query
     * inside the tenant transaction BuildExport's job opens before this
     * method is ever called, so it goes unused today.
     *
     * @param  array<string, mixed>  $parameters  validated against rules()
     * @return iterable<int, iterable<int, object>>
     */
    public function pages(string $tenantId, array $parameters): iterable;

    /**
     * Ordered CSV header to a per-row value extractor. Every money field
     * a source exposes contributes two entries here (`*_amount` as a raw
     * int, `*_currency` as the ISO 4217 code), never one combined,
     * formatted column (ADR 018: integer minor units, never floats).
     *
     * @return array<string, callable(object): (string|int|float|bool|null)>
     */
    public function columns(): array;
}
