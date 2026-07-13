<?php

namespace App\Identity\Data;

use App\Identity\Enums\DataSubjectRequestType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/customers/{customer}/data-subject-requests request body
 * (stage-12 plan, Endpoints "CreateDataSubjectRequestData: type"). type
 * validates against erasure only for now, not the raw DataSubjectRequestType
 * enum: export requests wait on the assembler and queued job (Slice 2,
 * task 6), mirroring App\Reporting\Data\CreateExportData's own
 * registered-types restriction. A syntactically valid "export" therefore
 * fails with the same request.validation_failed code as an unknown
 * string until that task widens the allowlist.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateDataSubjectRequestData extends Data
{
    public function __construct(
        public DataSubjectRequestType $type,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::in([DataSubjectRequestType::Erasure->value])],
        ];
    }
}
