<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Models\StaffPasswordResetToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffPasswordResetToken>
 */
class StaffPasswordResetTokenFactory extends Factory
{
    protected $model = StaffPasswordResetToken::class;

    /**
     * token_hash defaults to a random sha256-shaped string, not a real
     * App\Identity\Support\PasswordResetTokenHasher::hash() output,
     * mirroring App\Identity\Models\MfaRecoveryCode's own factory
     * precedent: callers proving the consumption guard compute their own
     * fixture hash directly instead of through this default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addMinutes(config()->integer('identity.reset_token_ttl_minutes')),
            'consumed_at' => null,
        ];
    }
}
