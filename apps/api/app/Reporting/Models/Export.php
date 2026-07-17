<?php

namespace App\Reporting\Models;

use App\Identity\Capability;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Support\Media\Contracts\HasMediaCapability;
use Database\Factories\Reporting\Models\ExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The export lifecycle record (stage-11 plan, Data model "exports"). The
 * generated file lands as a medialibrary attachment on the single-file
 * `export_file` collection (task 15, App\Reporting\Actions\BuildExport),
 * no bespoke path column (ADR 014). The three transitions below are the
 * exactly-one-worker claim (pending to processing) and the two terminal
 * edges from processing (to completed, to failed): each a single
 * conditional UPDATE guarded on the current status and checked by
 * affected-row count, never read-then-write (data-conventions, stage-11
 * plan, Data model "exports"), mirroring
 * App\Orders\Actions\Concerns\TransitionsOrderStatus and
 * App\Payments\Actions\ConfirmPayment's own posture. Placed on the model
 * itself rather than a dedicated Action class because BuildExport is the
 * only caller.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed> $parameters
 * @property string $requested_by_user_id
 * @property int|null $row_count
 * @property Carbon|null $completed_at
 * @property string|null $failure_code
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'type',
    'status',
    'parameters',
    'requested_by_user_id',
    'row_count',
    'completed_at',
    'failure_code',
])]
class Export extends Model implements HasMedia, HasMediaCapability
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    /**
     * Single file: a rebuilt export (this stage ships no rebuild-export
     * action, but a future retry would) replaces rather than
     * accumulates, mirroring App\Orders\Models\Ticket's own ticket_pdf
     * collection.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('export_file')
            ->useDisk(config()->string('media.protected_disk'))
            ->singleFile()
            ->acceptsMimeTypes(['text/csv']);
    }

    public function mediaManageCapability(): Capability
    {
        return Capability::ReportsExport;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'parameters' => 'array',
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
            ->where('status', ExportStatus::Pending)
            ->update(['status' => ExportStatus::Processing]) === 1;
    }

    public static function complete(string $id, int $rowCount): bool
    {
        return static::query()
            ->whereKey($id)
            ->where('status', ExportStatus::Processing)
            ->update([
                'status' => ExportStatus::Completed,
                'row_count' => $rowCount,
                'completed_at' => Date::now(),
            ]) === 1;
    }

    public static function fail(string $id, string $failureCode): bool
    {
        return static::query()
            ->whereKey($id)
            ->where('status', ExportStatus::Processing)
            ->update([
                'status' => ExportStatus::Failed,
                'failure_code' => $failureCode,
            ]) === 1;
    }
}
