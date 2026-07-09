<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Spectator\Spectator;
use Tests\Support\OpenApiSpec;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spectator.sources.local.base_path', dirname(OpenApiSpec::path()));
        Spectator::using(basename(OpenApiSpec::path()));

        self::registerConformanceMacros();
    }

    private static function registerConformanceMacros(): void
    {
        TestResponse::macro('assertConformsToOpenApi', function (): TestResponse {
            /** @var TestResponse<JsonResponse> $this */
            return $this->assertValidRequest()->assertValidResponse();
        });

        // Probe routes are absent from the spec by design, so their problem
        // responses validate against the shared component schemas instead of
        // a path entry.
        TestResponse::macro('assertMatchesProblemSchema', function (string $component = 'Problem'): TestResponse {
            /** @var TestResponse<JsonResponse> $this */
            $errors = OpenApiSpec::componentSchemaErrors($component, (string) $this->getContent());

            Assert::assertSame([], $errors, sprintf(
                'Response does not match the %s component schema: %s',
                $component,
                json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ));

            return $this;
        });
    }
}
