<?php

namespace Database\Factories\Payments\Models;

use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * tenant_id and payment_id have no default, mirroring PaymentFactory:
     * callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'money' => Money::of(1000, 'USD'),
            'status' => RefundStatus::Pending,
            'reason' => null,
            'ticket_ids' => null,
            'commission_amount' => 0,
            'idempotency_key' => Str::uuid7()->toString(),
            'request_hash' => hash('sha256', Str::uuid7()->toString()),
            'gateway_reference' => null,
            'failure_code' => null,
        ];
    }
}
