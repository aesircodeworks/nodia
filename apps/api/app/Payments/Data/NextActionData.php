<?php

namespace App\Payments\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class NextActionData extends Data
{
    public function __construct(
        public string $type,
        public ?string $redirect_url = null,
        public ?string $code = null,
    ) {}

    public static function none(): self
    {
        return new self('none');
    }

    public static function redirect(string $url): self
    {
        return new self('redirect', redirect_url: $url);
    }

    public static function displayCode(string $code): self
    {
        return new self('display_code', code: $code);
    }
}
