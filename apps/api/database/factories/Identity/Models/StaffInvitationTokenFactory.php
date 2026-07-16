<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Models\StaffInvitationToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffInvitationToken>
 */
class StaffInvitationTokenFactory extends Factory
{
    protected $model = StaffInvitationToken::class;

    /**
     * token_hash defaults to a random sha256-shaped string, not a real
     * App\Identity\Support\InvitationTokenHasher::hash() output, mirroring
     * StaffPasswordResetTokenFactory's own precedent: callers proving the
     * consumption guard compute their own fixture hash directly instead of
     * through this default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addMinutes(config()->integer('identity.invitation_token_ttl_minutes')),
            'consumed_at' => null,
        ];
    }
}
