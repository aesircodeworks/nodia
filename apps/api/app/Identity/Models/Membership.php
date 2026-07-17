<?php

namespace App\Identity\Models;

use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\InvalidMembershipScopeException;
use App\Models\User;
use Database\Factories\Identity\Models\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $tenant_id
 * @property string $role_id
 * @property MembershipScope $scope
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'tenant_id', 'role_id', 'scope'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::saving(function (self $membership): void {
            self::assertScopeInvariant($membership->scope, $membership->tenant_id);
        });
    }

    /**
     * A platform-scope membership implies the sentinel platform tenant
     * (stage-03 plan, Data model). Pulled out as a pure, DB-free static so
     * it is unit-testable on its own and so the InviteUser/AssignRole
     * Actions a later task adds inherit the guarantee automatically by
     * going through the model's saving hook, without repeating the check.
     */
    public static function assertScopeInvariant(MembershipScope $scope, string $tenantId): void
    {
        if ($scope === MembershipScope::Platform && $tenantId !== config()->string('tenancy.platform_tenant_id')) {
            throw InvalidMembershipScopeException::forNonSentinelTenant($tenantId);
        }
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * App\Models\User is not a bounded-context model (data-conventions,
     * task-04 journal), so referencing it here is unrestricted the same
     * way MembershipFactory already does.
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
            'scope' => MembershipScope::class,
        ];
    }
}
