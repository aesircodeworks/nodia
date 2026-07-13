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
 * fromModel()'s $downloadUrl parameter defaults to null, which is all
 * App\Identity\Actions\CreateDataSubjectRequest's own erasure (201) and
 * export (202) responses ever pass: neither a freshly completed erasure
 * (no file ever attached) nor a freshly created export (still pending)
 * has anything to sign yet. GET /v1/data-subject-requests/
 * {data_subject_request} (task breakdown item 6's own download-URL half)
 * is the one caller that passes a real signed URL, computed from the
 * model's own medialibrary attachment once it exists.
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

    public static function fromModel(DataSubjectRequest $request, ?string $downloadUrl = null): self
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
            $downloadUrl,
            CarbonImmutable::instance($request->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
