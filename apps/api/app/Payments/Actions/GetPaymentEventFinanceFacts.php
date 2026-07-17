<?php

namespace App\Payments\Actions;

use App\Orders\Actions\GetOrderEventIds;
use App\Payments\Data\PaymentEventFinanceFactsData;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Bulk payment ID to finance-facts lookup for the Stage 11 finance
 * projector (stage-11 plan, task 8, Data model "report_event_finance"):
 * ProjectEventFinance must never read ledger_entries (the ledger is an
 * independent outbox consumer with no cross-consumer ordering guarantee,
 * system-design 9.2), so it resolves the gateway fee and platform
 * commission it needs straight from the payment row's own persisted
 * facts (fee_amount, commission_amount, set once at confirmation time by
 * App\Payments\Actions\ConfirmPayment) rather than the ledger's derived
 * entries. event_id rides along, resolved through the Orders bulk
 * lookup Action, since neither the payment row nor PaymentConfirmed's
 * own payload carries it. A payment ID with no matching row, or whose
 * order has no matching event, is simply absent from the returned
 * collection; the caller decides how to treat a miss.
 */
final class GetPaymentEventFinanceFacts
{
    public function __construct(private readonly GetOrderEventIds $orderEventIds) {}

    /**
     * @param  list<string>  $paymentIds
     * @return Collection<string, PaymentEventFinanceFactsData> keyed by payment_id
     */
    public function __invoke(array $paymentIds): Collection
    {
        if ($paymentIds === []) {
            return collect();
        }

        $payments = Payment::query()
            ->whereIn('id', $paymentIds)
            ->get(['id', 'order_id', 'fee_amount', 'commission_amount', 'currency']);

        $eventIds = ($this->orderEventIds)($payments->pluck('order_id')->unique()->values()->all());

        return $payments
            ->mapWithKeys(function (Payment $payment) use ($eventIds): array {
                $eventId = $eventIds->get($payment->order_id);

                if ($eventId === null) {
                    return [];
                }

                return [$payment->id => new PaymentEventFinanceFactsData(
                    $payment->id,
                    $eventId,
                    Money::of($payment->fee_amount, $payment->currency),
                    Money::of($payment->commission_amount, $payment->currency),
                )];
            });
    }
}
