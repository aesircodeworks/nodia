<?php

declare(strict_types=1);

use App\Support\Testing\OutboxPayloadPiiExemptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/*
 * Stage-12 plan, Slice 6 / task breakdown item 14: the payload PII
 * meta-test. Erasure (system-design 14.3) anonymizes customers.name and
 * customers.email in place, but outbox rows are retained and replayed
 * (system-design 9.1), so a payload that ever carried a customer's name or
 * email would defeat erasure the moment history replays. Every payload
 * class under app/*Events/*Payload.php (event-conventions: concrete event
 * classes and their payloads live in the owning context's Events/
 * directory) is scanned by constructor parameter name and declared type
 * against name, email, and document-number patterns; a legitimate
 * non-personal field that happens to match (e.g. a tenant's own business
 * name) must be listed in App\Support\Testing\OutboxPayloadPiiExemptions
 * with a reason, or the scan fails the build.
 *
 * Proven red against a seeded gap during development (recorded in
 * docs/plans/execution/stage-12-hardening.md, task T14): adding a
 * temporary `public string $customerEmail` parameter to a payload class
 * failed this test with the exact "matches the disallowed pattern
 * [email]" message; removing it restored green.
 */

const DISALLOWED_PII_PATTERNS = ['email', 'name', 'document', 'passport', 'cpf', 'ssn', 'tax_id'];

/**
 * @return list<class-string<Data>>
 */
function outboxPayloadClasses(): array
{
    $classes = [];

    foreach (glob(base_path('app/*/Events/*Payload.php')) ?: [] as $file) {
        $relative = Str::after($file, base_path('app/'));
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        if (class_exists($class) && is_subclass_of($class, Data::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('registers at least one outbox payload Data class', function (): void {
    expect(outboxPayloadClasses())->not->toBeEmpty();
});

it('declares no field named or typed as PII on any registered outbox payload', function (): void {
    $exempt = OutboxPayloadPiiExemptions::exempt();
    $violations = [];

    foreach (outboxPayloadClasses() as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            continue;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $key = $class.'::$'.$parameter->getName();

            if (isset($exempt[$key])) {
                continue;
            }

            $normalizedName = Str::snake($parameter->getName());

            foreach (DISALLOWED_PII_PATTERNS as $pattern) {
                if (str_contains($normalizedName, $pattern)) {
                    $violations[] = "{$key} matches the disallowed pattern [{$pattern}]";
                }
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            if (is_a($typeName, Model::class, true)) {
                $violations[] = "{$key} is typed as an Eloquent model [{$typeName}], embedding its full row (including any PII) into the outbox payload";

                continue;
            }

            $normalizedType = Str::snake(class_basename($typeName));

            foreach (DISALLOWED_PII_PATTERNS as $pattern) {
                if (str_contains($normalizedType, $pattern)) {
                    $violations[] = "{$key} is typed as [{$typeName}], whose name matches the disallowed pattern [{$pattern}]";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
