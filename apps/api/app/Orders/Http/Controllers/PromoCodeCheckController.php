<?php

namespace App\Orders\Http\Controllers;

use App\EventCatalog\Actions\ResolveTicketTypePricing;
use App\Inventory\Actions\ResolveHoldForOrder;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Orders\Actions\EvaluatePromoCode;
use App\Orders\Data\CheckPromoCodeData;
use App\Orders\Data\PromoCodeCheckData;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only promo preview (stage-07 plan, Endpoints "POST
 * /v1/storefront/promo-codes/check"): evaluates the code against the
 * hold's priced subtotal and never increments usage_count. A hold owned
 * by a different customer is hold_not_found, mirroring conversion.
 */
class PromoCodeCheckController
{
    public function __invoke(
        Request $request,
        CheckPromoCodeData $data,
        ResolveHoldForOrder $resolveHold,
        ResolveTicketTypePricing $pricing,
        EvaluatePromoCode $evaluate,
    ): JsonResponse {
        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        $hold = $resolveHold($data->holdId) ?? throw HoldNotFoundException::forId($data->holdId);

        if ($hold->customerId !== null && $hold->customerId !== $customerId) {
            throw HoldNotFoundException::forId($data->holdId);
        }

        $prices = $pricing(array_map(fn ($item): string => $item->ticketTypeId, $hold->items));

        $subtotal = null;

        foreach ($hold->items as $item) {
            $line = $prices[$item->ticketTypeId]->price->multiplyBy($item->quantity);
            $subtotal = $subtotal === null ? $line : $subtotal->add($line);
        }

        $subtotal ??= Money::of(0, 'USD');

        return response()->json(PromoCodeCheckData::fromEvaluation($evaluate($data->code, $subtotal)));
    }
}
