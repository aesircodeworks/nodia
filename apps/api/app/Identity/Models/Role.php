<?php

namespace App\Identity\Models;

use App\Identity\Capability;
use App\Identity\Exceptions\RoleNotFoundException;
use App\Identity\Exceptions\UnknownCapabilityException;
use Database\Factories\Identity\Models\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property list<string> $capabilities
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['tenant_id', 'name', 'capabilities'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            self::assertKnownCapabilities($role->capabilities);
        });
    }

    /**
     * A NULL tenant_id is the sanctioned exception marking a global
     * template role maintained by the platform (data-conventions Tenancy,
     * stage-03 plan Data model), never editable by a tenant.
     */
    public function isTemplate(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Every stored capability must be a real entry in the Capability
     * registry (stage-03 plan, Roles and memberships endpoint table:
     * unknown_capability). Pulled out as a pure, DB-free static so it is
     * unit-testable on its own and so App\Identity\Actions\CreateRole and
     * UpdateRole inherit the guarantee automatically by going through the
     * model's saving hook, without repeating the check, mirroring
     * Membership::assertScopeInvariant's precedent (task-04 journal).
     *
     * @param  list<string>  $capabilities
     */
    public static function assertKnownCapabilities(array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            if (Capability::tryFrom($capability) === null) {
                throw UnknownCapabilityException::for($capability);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }

    /**
     * Shared by App\Identity\Actions\InviteUser and AssignRole, which both
     * need to resolve a request-supplied role id the same way
     * App\Identity\Http\Controllers\RoleController::roleOrFail() already
     * does for the role endpoints themselves: roles_template_or_tenant_read
     * RLS already makes another tenant's custom role invisible to a plain
     * find(), so a nonexistent id and a foreign tenant's role render
     * identically, and existence never leaks.
     */
    public static function visibleOrFail(string $id): self
    {
        return self::query()->find($id) ?? throw RoleNotFoundException::forId($id);
    }
}
