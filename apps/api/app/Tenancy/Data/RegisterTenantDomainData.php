<?php

namespace App\Tenancy\Data;

use App\Tenancy\Actions\RegisterDomain;
use Closure;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class RegisterTenantDomainData extends Data
{
    public function __construct(
        public string $domain,
        public bool|Optional $isPrimary,
    ) {}

    /**
     * The hostname rule mirrors the RegisterDomain invariant so garbage
     * renders as request validation instead of reaching the Action.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:253',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! RegisterDomain::isValidHostname(mb_strtolower($value))) {
                        $fail('The :attribute field must be a valid hostname.');
                    }
                },
            ],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
