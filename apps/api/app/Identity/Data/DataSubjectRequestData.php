<?php

namespace App\Identity\Data;

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\DataSubjectRequest;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The data subject request lifecycle wire shape (stage-12 plan, Endpoints
 * "DataSubjectRequestData: id, customer_id, type, status,
 * requested_by_user_id, completed_at, download_url, created_at").
 * download_url stays null unconditionally on this Data class: an erasure
 * never attaches a file, and a completed export's signed download URL is
 * computed by the GET /v1/data-subject-requests/{data_subject_request}
 * endpoint (task breakdown item 6's own download-URL half), not by
 * fromModel() here, which App\Identity\Actions\CreateDataSubjectRequest
 * uses to build both the 201 erasure and the 202 export response.
 */
#[MapName(SnakeCaseMapper::class)]
class DataSubjectRequestData extends Data
{
    public function __construct(
        public string $id,
        public string $customerId,
        public DataSubjectRequestType $type,
        public DataSubjectRequestStatus $status,
        public string $requestedByUserId,
        public ?string $completedAt,
        public ?string $downloadUrl,
        public string $createdAt,
    ) {}

    public static function fromModel(DataSubjectRequest $request): self
    {
        return new self(
            $request->id,
            $request->customer_id,
            $request->type,
            $request->status,
            $request->requested_by_user_id,
            $request->completed_at !== null
                ? CarbonImmutable::instance($request->completed_at)->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            null,
            CarbonImmutable::instance($request->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
