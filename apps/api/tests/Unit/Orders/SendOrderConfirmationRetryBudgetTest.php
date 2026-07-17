<?php

use App\Orders\Jobs\SendOrderConfirmation;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use Illuminate\Support\Str;

/*
 * Stage-08a plan, Slice 9: retry budget wiring, 5 attempts with linear
 * 1-minute backoff (system-design 13), declared per subscriber in
 * config/outbox.php and applied by ProcessOutboxDelivery at dispatch.
 */

it('gives the confirmation consumer 5 tries with 60-second backoff', function (): void {
    $job = new ProcessOutboxDelivery((string) Str::uuid7(), SendOrderConfirmation::NAME);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe(60);
});

it('leaves subscribers without a declared budget on the outbox-wide defaults', function (): void {
    $job = new ProcessOutboxDelivery((string) Str::uuid7(), 'handle_payment_confirmed');

    expect($job->tries)->toBe(40)
        ->and($job->backoff)->toBeNull();
});
