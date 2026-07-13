<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\DataSubjectRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSubjectRequest>
 */
class DataSubjectRequestFactory extends Factory
{
    protected $model = DataSubjectRequest::class;

    /**
     * tenant_id, customer_id, and requested_by_user_id have no default,
     * mirroring ExportFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DataSubjectRequestType::Erasure,
            'status' => DataSubjectRequestStatus::Pending,
            'completed_at' => null,
        ];
    }
}
