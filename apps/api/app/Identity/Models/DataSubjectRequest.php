<?php

namespace App\Identity\Models;

use App\Identity\Capability;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Support\Media\Contracts\HasMediaCapability;
use Database\Factories\Identity\Models\DataSubjectRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The auditable, idempotent erasure or export request record (stage-12
 * plan, Data model "data_subject_requests"). The two transitions below
 * are the exactly-one-worker claim (pending to processing) and the
 * completed terminal edge from processing: each a single conditional
 * UPDATE guarded on the current status and checked by affected-row
 * count, never read-then-write (data-conventions), mirroring
 * App\Reporting\Models\Export's own claim/complete/fail posture exactly.
 *
 * The generated export document lands as a medialibrary attachment on
 * the single-file `data_subject_export` collection (Slice 2, task
 * breakdown item 6, App\Identity\Actions\BuildDataSubjectExport), no
 * bespoke path column (ADR 014); an erasure request never attaches
 * anything, since AnonymizeCustomer produces no file.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property DataSubjectRequestType $type
 * @property DataSubjectRequestStatus $status
 * @property string $requested_by_user_id
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'customer_id',
    'type',
    'status',
    'requested_by_user_id',
    'completed_at',
])]
class DataSubjectRequest extends Model implements HasMedia, HasMediaCapability
{
    /** @use HasFactory<DataSubjectRequestFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    protected $table = 'data_subject_requests';

    /**
     * Single file: a rebuilt export (nothing in this stage retries a
     * failed build, but a future retry would) replaces rather than
     * accumulates, mirroring App\Reporting\Models\Export's own
     * export_file collection.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('data_subject_export')
            ->useDisk(config()->string('media.protected_disk'))
            ->singleFile()
            ->acceptsMimeTypes(['application/json']);
    }

    public function mediaManageCapability(): Capability
    {
        return Capability::CustomersExport;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataSubjectRequestType::class,
            'status' => DataSubjectRequestStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The exactly-one-worker claim: zero rows affected means another
     * worker already claimed it (or it is not pending), and the caller
     * exits cleanly rather than treating that as an error.
     */
    public static function claim(string $id): bool
    {
        return static::query()
            ->whereKey($id)
            ->where('status', DataSubjectRequestStatus::Pending)
            ->update(['status' => DataSubjectRequestStatus::Processing]) === 1;
    }

    public static function complete(string $id): bool
    {
        return static::query()
            ->whereKey($id)
            ->where('status', DataSubjectRequestStatus::Processing)
            ->update([
                'status' => DataSubjectRequestStatus::Completed,
                'completed_at' => Date::now(),
            ]) === 1;
    }

    public static function fail(string $id): bool
    {
        return static::query()
            ->whereKey($id)
            ->where('status', DataSubjectRequestStatus::Processing)
            ->update(['status' => DataSubjectRequestStatus::Failed]) === 1;
    }
}
