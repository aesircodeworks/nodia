<?php

use App\Orders\Mail\OrderConfirmationMail;
use App\Support\Money\Money;

it('formats the total from integer minor units without floating-point loss', function (): void {
    $mail = new OrderConfirmationMail('Ana', 2, Money::of(9007199254740993, 'BRL'));

    expect($mail->render())->toContain('BRL 90,071,992,547,409.93');
});

it('formats sub-unit totals with two decimal places', function (): void {
    $mail = new OrderConfirmationMail('Ana', 1, Money::of(5, 'BRL'));

    expect($mail->render())->toContain('BRL 0.05');
});
