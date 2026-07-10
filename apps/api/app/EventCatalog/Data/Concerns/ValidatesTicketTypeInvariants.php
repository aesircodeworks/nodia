<?php

namespace App\EventCatalog\Data\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Shared request-layer validation for CreateTicketTypeData and
 * UpdateTicketTypeData (stage-05a plan, task breakdown item 8).
 * sales_start and sales_end are each independently nullable
 * (ticket_types_sales_window CHECK only fires when both are set; the
 * task-06 isolation suite proves one side alone is accepted), so create
 * never requires them together: the ordering check only runs when both
 * are present and non-null in the submitted payload. Update cannot merge
 * a partial payload against the target TicketType's stored columns (no
 * model access from a static Data class, the same limitation
 * App\EventCatalog\Data\Concerns\ValidatesEventInvariants documents for
 * events), so there $requireTogether closes the gap a one-sided PATCH
 * would otherwise leave for the database CHECK to catch as an unhandled
 * 500: whenever either key is given, both must be given.
 */
trait ValidatesTicketTypeInvariants
{
    /**
     * @param  array<string, mixed>  $data
     */
    private static function addSalesWindowErrors(Validator $validator, array $data, bool $requireTogether): void
    {
        $hasStart = array_key_exists('sales_start', $data);
        $hasEnd = array_key_exists('sales_end', $data);

        if ($requireTogether && $hasStart !== $hasEnd) {
            $validator->errors()->add(
                $hasStart ? 'sales_end' : 'sales_start',
                'sales_start and sales_end must be given together.',
            );

            return;
        }

        if (! $hasStart || ! $hasEnd || $data['sales_start'] === null || $data['sales_end'] === null) {
            return;
        }

        if ($validator->errors()->has('sales_start') || $validator->errors()->has('sales_end')) {
            return;
        }

        if (Carbon::parse($data['sales_end'])->lessThanOrEqualTo(Carbon::parse($data['sales_start']))) {
            $validator->errors()->add('sales_end', 'The sales_end field must be a date after sales_start.');
        }
    }
}
