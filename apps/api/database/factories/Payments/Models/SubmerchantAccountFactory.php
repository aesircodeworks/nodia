<?php

namespace Database\Factories\Payments\Models;

use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmerchantAccount>
 */
class SubmerchantAccountFactory extends Factory
{
    protected $model = SubmerchantAccount::class;

    /**
     * tenant_id has no default, mirroring PaymentFactory: callers pass
     * a real id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'fake',
            'status' => SubmerchantStatus::Pending,
            'gateway_account_reference' => null,
            'onboarding_url' => null,
            'requirements' => [],
            'activated_at' => null,
        ];
    }
}
