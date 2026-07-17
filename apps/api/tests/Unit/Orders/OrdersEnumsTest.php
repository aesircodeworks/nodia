<?php

use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PromoCodeDiscountType;
use App\Orders\Enums\TicketStatus;

test('the order status enum holds exactly the system-design 7.1 states', function () {
    expect(array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()))->toBe([
        'pending',
        'awaiting_payment',
        'paid',
        'expired',
        'failed',
        'canceled',
        'partially_refunded',
        'refunded',
    ]);
});

test('the ticket status enum holds exactly the known states', function () {
    expect(array_map(fn (TicketStatus $status) => $status->value, TicketStatus::cases()))->toBe([
        'issued',
        'canceled',
        'refunded',
    ]);
});

test('the promo code discount type enum holds exactly the known types', function () {
    expect(array_map(fn (PromoCodeDiscountType $type) => $type->value, PromoCodeDiscountType::cases()))->toBe([
        'percentage',
        'fixed_amount',
    ]);
});
