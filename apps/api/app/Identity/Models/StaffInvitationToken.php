<?php

namespace App\Identity\Models;

use App\Models\User;
use Database\Factories\Identity\Models\StaffInvitationTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use staff invitation acceptance token (stage-03 plan, task
 * breakdown item 9). Platform-global like `users` and
 * `staff_password_reset_tokens`, no tenant_id, no RLS
 * (2026_07_16_000000_create_staff_invitation_tokens_table.php grants
 * unscoped access): an acceptance token is authentication infrastructure
 * keyed to a staff identity, not tenant-scoped domain data.
 *
 * Deliberately carries no consumption logic of its own, the same
 * separation StaffPasswordResetToken establishes: atomic single-use
 * consumption (`UPDATE ... SET consumed_at = now() WHERE token_hash = ?
 * AND consumed_at IS NULL AND expires_at > now()`, checked by affected-row
 * count) is App\Identity\Actions\ConsumeInvitationToken.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'expires_at', 'consumed_at'])]
class StaffInvitationToken extends Model
{
    /** @use HasFactory<StaffInvitationTokenFactory> */
    use HasFactory, HasUuids;

    /**
     * App\Models\User is not a bounded-context model (data-conventions,
     * task-04 journal), so referencing it here is unrestricted the same
     * way App\Identity\Models\StaffPasswordResetToken::user() already does.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
