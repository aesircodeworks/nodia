<?php

namespace Database\Factories\Orders\Models;

use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSigningKey>
 */
class EventSigningKeyFactory extends Factory
{
    protected $model = EventSigningKey::class;

    /**
     * tenant_id and event_id have no default: callers pass real ids
     * explicitly, mirroring TicketFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key_version' => 1,
            'secret' => $this->faker->sha256(),
            'status' => SigningKeyStatus::Active,
            'activated_at' => now(),
            'retired_at' => null,
            'revoked_at' => null,
        ];
    }
}
