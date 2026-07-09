<?php

namespace App\Identity\Models;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }
}
