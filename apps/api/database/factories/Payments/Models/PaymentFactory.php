<?php

namespace Database\Factories\Payments\Models;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * tenant_id and order_id have no default, mirroring OrderFactory:
     * callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'fake',
            'method' => 'card',
            'idempotency_key' => Str::uuid7()->toString(),
            'request_hash' => hash('sha256', Str::uuid7()->toString()),
            'gateway_reference' => null,
            'money' => Money::of(5000, 'USD'),
            'status' => PaymentStatus::Initiated,
        ];
    }
}
