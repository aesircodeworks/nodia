<?php

namespace App\Reporting\Data;

use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\ExportSourceRegistry;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;

/**
 * POST /v1/exports request body (stage-11 plan, Endpoints
 * "CreateExportData: type, parameters"). type validates against
 * App\Reporting\Support\Export\ExportSourceRegistry's currently
 * registered types, not the raw ExportType enum (task 16's own
 * instruction): a syntactically valid but unregistered type is rejected
 * with the same request.validation_failed code as a nonsense string,
 * since Rule::in() is built from the registry's own registeredTypes()
 * every time rules() runs.
 *
 * parameters' own per-type shape (which of event_id, from, to apply, and
 * whether any is prohibited) is resolved dynamically in rules() from the
 * requested type's own registered ExportSource::rules(), each prefixed
 * onto the parameters.* path: laravel-data calls rules() through the
 * container (Spatie\LaravelData\Resolvers\DataValidationRulesResolver),
 * so both ExportSourceRegistry and the raw request payload (via
 * ValidationContext::$payload, needed to read the sibling type value
 * before parameters' own rules can be resolved) are available as
 * ordinary method parameters, not statics.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateExportData extends Data
{
    public function __construct(
        public ExportType $type,
        #[TypeScriptOptional]
        public ExportParametersData|Optional $parameters,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(ValidationContext $context, ExportSourceRegistry $registry): array
    {
        $registeredTypeValues = array_map(
            static fn (ExportType $type): string => $type->value,
            $registry->registeredTypes(),
        );

        $rules = [
            'type' => ['required', 'string', Rule::in($registeredTypeValues)],
            'parameters' => ['sometimes', 'array'],
        ];

        $requestedType = ExportType::tryFrom((string) ($context->payload['type'] ?? ''));

        if ($requestedType !== null && $registry->has($requestedType)) {
            foreach ($registry->get($requestedType)->rules() as $key => $sourceRules) {
                $rules["parameters.{$key}"] = $sourceRules;
            }
        }

        return $rules;
    }
}
