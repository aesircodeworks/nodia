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
 * validates explicitly against the full DataSubjectRequestType enum via
 * Rule::in() rather than relying on the property's own enum cast to
 * reject an unknown value, mirroring App\Reporting\Data\
 * CreateExportData's own registered-types restriction: erasure and
 * export are both accepted now that Slice 2 (task 6) lands the export
 * assembler and queued job, so the allowlist covers every case the enum
 * itself declares.
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
            'type' => ['required', Rule::in([
                DataSubjectRequestType::Erasure->value,
                DataSubjectRequestType::Export->value,
            ])],
        ];
    }
}
