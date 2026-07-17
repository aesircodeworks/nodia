<?php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

final class OpenApiSpec
{
    /**
     * The official OpenAPI 3.1 meta-schema, vendored verbatim from this URL
     * (Apache 2.0, OpenAPI Initiative) so the validity check runs offline.
     */
    public const string META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2025-09-15';

    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private static ?stdClass $document = null;

    private static ?Validator $validator = null;

    public static function path(): string
    {
        // Resolved without the container so Contract datasets can enumerate
        // the spec before the application boots.
        return dirname(__DIR__, 4).'/docs/openapi/openapi.yaml';
    }

    public static function document(): stdClass
    {
        return self::$document ??= json_decode(
            json_encode(Yaml::parseFile(self::path()), JSON_THROW_ON_ERROR),
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function documentErrors(): array
    {
        $result = self::validator()->validate(self::document(), self::META_SCHEMA_ID);

        if ($result->isValid()) {
            return [];
        }

        return (new ErrorFormatter)->format($result->error());
    }

    /**
     * Validate a JSON body against one named component schema. The wrapper
     * adds unevaluatedProperties: false so undeclared members fail even when
     * the component itself is a non-strict base like Problem.
     *
     * @return array<string, list<string>>
     */
    public static function componentSchemaErrors(string $component, string $json): array
    {
        $schema = (object) [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$ref' => '#/components/schemas/'.$component,
            'unevaluatedProperties' => false,
            'components' => self::document()->components,
        ];

        $result = self::validator()->validate(json_decode($json, flags: JSON_THROW_ON_ERROR), $schema);

        if ($result->isValid()) {
            return [];
        }

        return (new ErrorFormatter)->format($result->error());
    }

    /**
     * Every operation in the spec as "method /path" strings.
     *
     * @return list<string>
     */
    public static function documentedOperations(): array
    {
        $operations = [];

        foreach (get_object_vars(self::document()->paths) as $path => $pathItem) {
            foreach (array_keys(get_object_vars($pathItem)) as $method) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $operations[] = $method.' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * Every response body schema in the spec, keyed by
     * "method /path status content-type".
     *
     * @return array<string, stdClass>
     */
    public static function documentedResponseSchemas(): array
    {
        $schemas = [];

        foreach (get_object_vars(self::document()->paths) as $path => $pathItem) {
            foreach (get_object_vars($pathItem) as $method => $operation) {
                if (! in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                foreach (get_object_vars($operation->responses ?? new stdClass) as $status => $response) {
                    foreach (get_object_vars($response->content ?? new stdClass) as $contentType => $mediaType) {
                        if (isset($mediaType->schema)) {
                            $schemas["{$method} {$path} {$status} {$contentType}"] = $mediaType->schema;
                        }
                    }
                }
            }
        }

        return $schemas;
    }

    /**
     * Follow internal $ref pointers until a concrete schema is reached.
     */
    public static function resolveSchema(stdClass $schema): stdClass
    {
        $guard = 0;

        while (isset($schema->{'$ref'})) {
            if ($guard++ > 32) {
                throw new RuntimeException('Circular $ref chain in docs/openapi/openapi.yaml.');
            }

            $ref = $schema->{'$ref'};

            if (! str_starts_with($ref, '#/')) {
                throw new RuntimeException("External \$ref [{$ref}] is not supported; keep the spec self-contained.");
            }

            $schema = self::document();

            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = strtr($segment, ['~1' => '/', '~0' => '~']);
                $schema = $schema->{$segment} ?? throw new RuntimeException("Unresolvable \$ref [{$ref}].");
            }
        }

        return $schema;
    }

    private static function validator(): Validator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }

        $validator = new Validator;

        // opis writes "default" values into the instance while validating, which
        // the meta-schema's own unevaluatedProperties then rejects.
        $validator->parser()->setOption('allowDefaults', false);

        // opis resolves $dynamicRef with $recursiveRef semantics and never finds
        // the $dynamicAnchor inside $defs/schema, so the ref is rewritten to the
        // equivalent static pointer before registration.
        $metaSchema = str_replace(
            '"$dynamicRef": "#meta"',
            '"$ref": "#/$defs/schema"',
            file_get_contents(__DIR__.'/oas-3.1-schema-2025-09-15.json'),
        );

        $validator->resolver()->registerRaw($metaSchema);

        return self::$validator = $validator;
    }
}
