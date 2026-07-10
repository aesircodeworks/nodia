<?php

namespace App\Identity\Data;

use App\Identity\Models\Role;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class RoleData extends Data
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $name,
        public array $capabilities,
        public bool $isTemplate,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self(
            $role->id,
            $role->tenant_id,
            $role->name,
            $role->capabilities,
            $role->isTemplate(),
        );
    }
}
