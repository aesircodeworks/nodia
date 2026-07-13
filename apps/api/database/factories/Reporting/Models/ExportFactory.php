<?php

namespace Database\Factories\Reporting\Models;

use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Models\Export;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * tenant_id and requested_by_user_id have no default, mirroring
     * DailySalesFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ExportType::Orders,
            'status' => ExportStatus::Pending,
            'parameters' => [],
            'row_count' => null,
            'completed_at' => null,
            'failure_code' => null,
        ];
    }
}
