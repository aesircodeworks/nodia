<?php

namespace App\EventCatalog\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * events.async_payment_policy (stage-05a plan, Data model): the minimal
 * shape Stage 8a needs to decide whether slow-confirming payment methods
 * stay offered as an event's remaining inventory runs low (system-design
 * 7.4). Cast directly onto App\EventCatalog\Models\Event via laravel-data's
 * Eloquent Castable support (Spatie\LaravelData\Contracts\TransformableData
 * extends Illuminate\Contracts\Database\Eloquent\Castable), so the model's
 * casts() array names this class directly rather than 'array', matching
 * the plan's "laravel-data object cast" description. Evolution of this
 * shape is additive only (stage-05a plan, Data model).
 */
#[MapName(SnakeCaseMapper::class)]
class AsyncPaymentPolicyData extends Data
{
    public function __construct(
        public bool $slowMethodsEnabled = true,
        public ?int $lowInventoryCutoff = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'slow_methods_enabled' => ['boolean'],
            'low_inventory_cutoff' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
