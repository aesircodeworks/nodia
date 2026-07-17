<?php

declare(strict_types=1);

use App\Support\Correlation\CorrelationId;
use Illuminate\Support\Str;

it('generates a UUIDv7 on first read when unset', function () {
    $correlationId = new CorrelationId;

    $value = $correlationId->get();

    expect($value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('returns the same value on subsequent reads within a scope', function () {
    $correlationId = new CorrelationId;

    $first = $correlationId->get();
    $second = $correlationId->get();

    expect($second)->toBe($first);
});

it('returns a previously assigned value without regenerating', function () {
    $correlationId = new CorrelationId;
    $correlationId->set('client-provided-id');

    expect($correlationId->get())->toBe('client-provided-id');
});

it('reports whether a value has been assigned or generated', function () {
    $correlationId = new CorrelationId;

    expect($correlationId->has())->toBeFalse();

    $correlationId->get();

    expect($correlationId->has())->toBeTrue();
});

it('resolves as one instance per request scope from the container', function () {
    $first = app(CorrelationId::class);
    $second = app(CorrelationId::class);

    expect($second)->toBe($first);

    $first->set('scope-stable-id');
    app()->forgetScopedInstances();

    $fresh = app(CorrelationId::class);

    expect($fresh)->not->toBe($first)
        ->and($fresh->has())->toBeFalse()
        ->and($fresh->get())->not->toBe('scope-stable-id')
        ->and(Str::isUuid($fresh->get()))->toBeTrue();
});
