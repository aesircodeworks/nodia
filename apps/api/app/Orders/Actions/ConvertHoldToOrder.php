<?php

namespace App\Orders\Actions;

use App\EventCatalog\Actions\ResolveTicketTypePricing;
use App\EventCatalog\Data\PricedTicketTypeData;
use App\Inventory\Actions\AttachHoldCustomer;
use App\Inventory\Actions\ResolveHoldForOrder;
use App\Inventory\Data\HoldForOrderData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Orders\Data\AppliedPromoCode;
use App\Orders\Data\CreateOrderData;
use App\Orders\Data\OrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Events\OrderCreated;
use App\Orders\Exceptions\HoldAlreadyConvertedException;
use App\Orders\Exceptions\HoldExpiredException;
use App\Orders\Models\Order;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Spatie\LaravelData\Optional;

/**
 * Creates a pending order from a valid hold (system-design 7.1,
 * stage-07 plan Slice 1). Inventory stays held: nothing commits until
 * the paid transition. Runs inside the ambient request transaction;
 * every guard is either a conditional UPDATE checked by affected-row
 * count (customer attachment, through Inventory's AttachHoldCustomer
 * seam) or a constraint the database enforces (the unique hold_id index
 * that makes double conversion structurally impossible). A hold owned
 * by a different customer converts as hold_not_found so hold ids never
 * leak ownership, and a released hold is equally gone from the buyer's
 * point of view.
 */
final class ConvertHoldToOrder
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
        private readonly ResolveHoldForOrder $resolveHold,
        private readonly AttachHoldCustomer $attachCustomer,
        private readonly ResolveTicketTypePricing $pricing,
        private readonly ApplyPromoCode $applyPromoCode,
    ) {}

    public function __invoke(CreateOrderData $data, string $customerId): OrderData
    {
        $hold = ($this->resolveHold)($data->holdId) ?? throw HoldNotFoundException::forId($data->holdId);

        if ($hold->customerId !== null && $hold->customerId !== $customerId) {
            throw HoldNotFoundException::forId($data->holdId);
        }

        if ($hold->status === HoldStatus::Committed) {
            throw HoldAlreadyConvertedException::forId($data->holdId);
        }

        if ($hold->status !== HoldStatus::Active) {
            throw HoldNotFoundException::forId($data->holdId);
        }

        if ($hold->expiresAt->lessThanOrEqualTo(Date::now())) {
            throw HoldExpiredException::forId($data->holdId);
        }

        if (Order::query()->where('hold_id', $hold->id)->exists()) {
            throw HoldAlreadyConvertedException::forId($data->holdId);
        }

        if (! ($this->attachCustomer)($hold->id, $customerId)) {
            throw HoldNotFoundException::forId($data->holdId);
        }

        $prices = ($this->pricing)(array_map(
            fn ($item): string => $item->ticketTypeId,
            $hold->items,
        ));

        $subtotal = $this->subtotal($hold, $prices);

        $applied = $data->promoCode instanceof Optional
            ? null
            : ($this->applyPromoCode)($data->promoCode, $subtotal);

        $order = $this->createOrder($hold, $customerId, $subtotal, $applied);

        $attendeeNames = $data->attendeeNames instanceof Optional ? [] : $data->attendeeNames;

        foreach ($hold->items as $item) {
            $order->items()->create([
                'tenant_id' => $order->tenant_id,
                'ticket_type_id' => $item->ticketTypeId,
                'quantity' => $item->quantity,
                'unit_price' => $this->priceFor($item->ticketTypeId, $prices),
                'attendee_names' => $attendeeNames[$item->ticketTypeId] ?? null,
            ]);
        }

        $this->outbox->record(OrderCreated::fromOrder($order));

        return OrderData::fromModel($order->load('items'));
    }

    /**
     * @param  array<string, PricedTicketTypeData>  $prices
     */
    private function subtotal(HoldForOrderData $hold, array $prices): Money
    {
        $subtotal = null;

        foreach ($hold->items as $item) {
            $line = $this->priceFor($item->ticketTypeId, $prices)->multiplyBy($item->quantity);
            $subtotal = $subtotal === null ? $line : $subtotal->add($line);
        }

        return $subtotal ?? throw new RuntimeException(sprintf('Hold "%s" has no items to price.', $hold->id));
    }

    /**
     * @param  array<string, PricedTicketTypeData>  $prices
     */
    private function priceFor(string $ticketTypeId, array $prices): Money
    {
        $priced = $prices[$ticketTypeId]
            ?? throw new RuntimeException(sprintf('Ticket type "%s" is not priceable.', $ticketTypeId));

        return $priced->price;
    }

    private function createOrder(HoldForOrderData $hold, string $customerId, Money $subtotal, ?AppliedPromoCode $applied): Order
    {
        $zero = Money::of(0, $subtotal->currency);
        $discount = $applied?->discount ?? $zero;

        try {
            return Order::query()->create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'customer_id' => $customerId,
                'event_id' => $hold->eventId,
                'promo_code_id' => $applied?->promoCodeId,
                'hold_id' => $hold->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'fees' => $zero,
                'total' => $subtotal->subtract($discount),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw HoldAlreadyConvertedException::forId($hold->id);
        }
    }
}
