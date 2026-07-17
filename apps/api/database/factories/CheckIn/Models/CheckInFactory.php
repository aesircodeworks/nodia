<?php

namespace Database\Factories\CheckIn\Models;

use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    /**
     * tenant_id, ticket_id, event_id, and user_id have no default:
     * callers pass real ids explicitly, mirroring TicketFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => 'device-'.$this->faker->uuid(),
            'client_scan_id' => Str::uuid7()->toString(),
            'result' => CheckInResult::Accepted,
            'scanned_at' => now(),
            'synced_at' => now(),
        ];
    }
}
