<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/customers (stage-03 plan, task breakdown item 13): guest
 * creation (password omitted or null) and full registration (password
 * present) are the same request shape, distinguished only by whether a
 * password was supplied (system-design 5.2, ADR 007). locale is likewise
 * optional and nullable: RegisterCustomer falls back to the tenant's own
 * default_locale when it is absent (system-design 12).
 */
#[MapName(SnakeCaseMapper::class)]
class RegisterCustomerData extends Data
{
    public function __construct(
        public string $email,
        public string $name,
        public string|Optional|null $password,
        public string|Optional|null $locale,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'name' => ['required', 'string'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'locale' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
