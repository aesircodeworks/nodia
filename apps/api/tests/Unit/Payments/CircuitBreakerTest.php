<?php

use App\Payments\Support\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
 * Stage-08a plan, Slice 8: closed to open after the failure threshold,
 * half-open probe after cooldown with the fake clock, close on probe
 * success, reopen on probe failure.
 */

beforeEach(function (): void {
    Cache::flush();
    config()->set('payments.circuit_breaker.failure_threshold', 3);
    config()->set('payments.circuit_breaker.cooldown_seconds', 60);
    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:00:00Z'));
    $this->breaker = app(CircuitBreaker::class);
});

it('stays closed below the failure threshold', function (): void {
    $this->breaker->recordFailure('fake');
    $this->breaker->recordFailure('fake');

    expect($this->breaker->isOpen('fake'))->toBeFalse()
        ->and($this->breaker->allowsRequest('fake'))->toBeTrue();
});

it('opens after the failure threshold and rejects requests', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    expect($this->breaker->isOpen('fake'))->toBeTrue()
        ->and($this->breaker->allowsRequest('fake'))->toBeFalse();
});

it('half-opens after the cooldown, allowing a probe', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:01:01Z'));

    expect($this->breaker->isOpen('fake'))->toBeFalse()
        ->and($this->breaker->allowsRequest('fake'))->toBeTrue();
});

it('closes on probe success', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:01:01Z'));

    $this->breaker->recordSuccess('fake');

    $this->breaker->recordFailure('fake');

    expect($this->breaker->isOpen('fake'))->toBeFalse();
});

it('reopens immediately when the half-open probe fails', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    $this->travelTo(CarbonImmutable::parse('2026-07-11T12:01:01Z'));

    $this->breaker->recordFailure('fake');

    expect($this->breaker->isOpen('fake'))->toBeTrue();
});

it('scopes state per gateway', function (): void {
    foreach (range(1, 3) as $ignored) {
        $this->breaker->recordFailure('fake');
    }

    expect($this->breaker->isOpen('fake'))->toBeTrue()
        ->and($this->breaker->isOpen('other'))->toBeFalse();
});
