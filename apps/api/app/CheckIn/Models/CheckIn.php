<?php

namespace App\CheckIn\Models;

use App\CheckIn\Enums\CheckInResult;
use Database\Factories\CheckIn\Models\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per scan attempt (system-design 8.3 CHECK_IN; stage-09 plan,
 * Data model "check_ins"). ticket_id and user_id are DB-level FKs into
 * Orders and Identity tables respectively, permitted because the code
 * boundary rule concerns model imports and cross-context queries, not
 * the FK itself (stage-09 plan, Data model "check_ins" note on the
 * ticket_id FK). event_id is denormalized so manifest overlays and
 * event-scoped queries stay single-table for this context.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $event_id
 * @property string $user_id
 * @property string $device_id
 * @property string $client_scan_id
 * @property CheckInResult $result
 * @property Carbon $scanned_at
 * @property Carbon $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'ticket_id',
    'event_id',
    'user_id',
    'device_id',
    'client_scan_id',
    'result',
    'scanned_at',
    'synced_at',
])]
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => CheckInResult::class,
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
