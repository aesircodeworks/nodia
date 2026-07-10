<?php

namespace App\Identity\Models;

use App\Models\User;
use Database\Factories\Identity\Models\MfaRecoveryCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use MFA recovery code (stage-03 plan, Data model). Platform-
 * global like `users`, no tenant_id, no RLS
 * (2026_07_09_000016_create_mfa_recovery_codes_table.php grants unscoped
 * access): a recovery code is authentication infrastructure keyed to a
 * staff identity, not tenant-scoped domain data.
 *
 * Deliberately carries no consumption logic of its own. Atomic single-use
 * consumption (`UPDATE ... SET used_at = now() WHERE user_id = ? AND
 * code_hash = ? AND used_at IS NULL`, checked by affected-row count) is
 * the guard task breakdown item 11 (Slice 5) implements as
 * App\Identity\Actions\ConsumeRecoveryCode; this task (item 10) ships only
 * the migration and this plain Eloquent mapping, plus failing tests for
 * that not-yet-built guard.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'code_hash', 'used_at'])]
class MfaRecoveryCode extends Model
{
    /** @use HasFactory<MfaRecoveryCodeFactory> */
    use HasFactory, HasUuids;

    /**
     * App\Models\User is not a bounded-context model (data-conventions,
     * task-04 journal), so referencing it here is unrestricted the same
     * way Membership::user() already does.
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
            'used_at' => 'datetime',
        ];
    }
}
