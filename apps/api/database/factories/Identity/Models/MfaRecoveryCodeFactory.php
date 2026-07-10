<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MfaRecoveryCode>
 */
class MfaRecoveryCodeFactory extends Factory
{
    protected $model = MfaRecoveryCode::class;

    /**
     * code_hash defaults to a random sha256-shaped string, not a real
     * App\Identity\Support\RecoveryCodeHasher::hash() output: that class
     * does not exist yet (task breakdown item 11, Slice 5). Callers
     * proving the not-yet-built consumption guard compute their own fixture
     * hash directly instead of through this default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => hash('sha256', fake()->unique()->uuid()),
            'used_at' => null,
        ];
    }
}
