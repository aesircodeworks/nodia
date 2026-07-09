<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

final class TtlProbe
{
    public function __construct(public readonly CarbonImmutable $expiresAt) {}

    public static function issue(int $ttlMinutes): self
    {
        return new self(Date::now()->toImmutable()->addMinutes($ttlMinutes));
    }

    public function isExpired(): bool
    {
        return Date::now()->greaterThanOrEqualTo($this->expiresAt);
    }
}

it('resolves framework time as immutable instances', function (): void {
    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class)
        ->and(now())->toBeInstanceOf(CarbonImmutable::class);
});

it('holds a ttl under a frozen clock without sleeping', function (): void {
    $this->freezeTime();

    $probe = TtlProbe::issue(10);

    expect($probe->isExpired())->toBeFalse();
});

it('expires a ttl when the clock travels past it', function (): void {
    $this->freezeTime();

    $probe = TtlProbe::issue(10);

    $this->travel(11)->minutes();

    expect($probe->isExpired())->toBeTrue();
});

it('treats the exact expiry instant as expired', function (): void {
    $this->freezeTime();

    $probe = TtlProbe::issue(10);

    $this->travel(10)->minutes();

    expect($probe->isExpired())->toBeTrue();
});
