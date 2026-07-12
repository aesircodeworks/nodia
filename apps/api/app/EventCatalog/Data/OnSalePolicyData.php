<?php

namespace App\EventCatalog\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * events.on_sale_policy (stage-10 plan, Data model): per-event
 * high-demand configuration the waiting room and gatekeeper (later
 * stage-10 tasks) read to decide whether an event enforces admission and
 * at what rate. Cast directly onto App\EventCatalog\Models\Event via
 * laravel-data's Eloquent Castable support, mirroring
 * App\EventCatalog\Data\AsyncPaymentPolicyData exactly (that class's own
 * docblock explains the mechanism). Evolution of this shape is additive
 * only (stage-10 plan, Data model).
 */
#[MapName(SnakeCaseMapper::class)]
class OnSalePolicyData extends Data
{
    public function __construct(
        public bool $highDemand = false,
        public ?int $admissionRatePerMinute = null,
        public bool $challengeRequired = false,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'high_demand' => ['boolean'],
            'admission_rate_per_minute' => ['nullable', 'integer', 'min:1'],
            'challenge_required' => ['boolean'],
        ];
    }
}
