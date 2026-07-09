<?php

namespace App\Tenancy\Data;

use App\Tenancy\Actions\RegisterDomain;
use Closure;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class DomainVerificationData extends Data
{
    public function __construct(
        public string $domain,
    ) {}

    /**
     * The hostname rule mirrors the RegisterDomain invariant: a value that
     * could never have been stored is a validation failure, not an unknown
     * domain.
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
        ];
    }
}
