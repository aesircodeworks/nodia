<?php

namespace App\Orders\Models;

use App\Orders\Enums\SigningKeyStatus;
use Database\Factories\Orders\Models\EventSigningKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A versioned per-event QR signing key (stage-09 plan, Data model
 * "event_signing_keys"). secret is encrypted at rest via Laravel's
 * encrypted cast and never leaves the API except through the manifest
 * key endpoint. event_id is a DB-level FK into EventCatalog, permitted
 * because the code boundary rule concerns model imports and
 * cross-context queries, not the FK itself.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property int $key_version
 * @property string $secret
 * @property SigningKeyStatus $status
 * @property Carbon $activated_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'key_version',
    'secret',
    'status',
    'activated_at',
    'retired_at',
    'revoked_at',
])]
class EventSigningKey extends Model
{
    /** @use HasFactory<EventSigningKeyFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SigningKeyStatus::class,
            'secret' => 'encrypted',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
