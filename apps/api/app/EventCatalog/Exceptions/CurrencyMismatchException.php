<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by CreateTicketType/UpdateTicketType when a ticket type's price
 * currency does not match the tenant's settlement currency (stage-05a
 * plan, endpoint table: "catalog.currency_mismatch (422)"; section 12:
 * "currency ... constrained to the tenant's settlement currency"). This
 * is a distinct stable code, not the generic request.validation_failed:
 * ProblemRenderer always renders an Illuminate\Validation\ValidationException
 * as request.validation_failed, so the boundary check against
 * App\Tenancy\Actions\ResolveTenantSettlementCurrency (a database read)
 * lives in the Action, not in a Data class's withValidator hook, mirroring
 * App\EventCatalog\Exceptions\EventImmutableException's own precedent of a
 * dedicated HasErrorCode exception living outside request validation.
 * Distinct from the unrelated App\Support\Money\CurrencyMismatchException,
 * which guards Money's own same-currency arithmetic and is never meant to
 * reach the HTTP boundary.
 */
final class CurrencyMismatchException extends RuntimeException implements HasErrorCode
{
    public static function between(string $settlementCurrency, string $actual): self
    {
        return new self(sprintf(
            'Ticket type currency "%s" does not match the tenant settlement currency "%s".',
            $actual,
            $settlementCurrency,
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogCurrencyMismatch;
    }
}
