<?php

namespace Rushing\LaravelDataSchemasScribe\Support;

/**
 * Adapter glue: flatten a generator schema's top-level properties into Scribe's
 * flat body-parameter shape. This feeds Scribe's HTML/Postman/try-it-out only —
 * the OpenAPI artifact gets the full $ref-based schema from the OpenApiGenerator
 * hook. No type→schema mapping happens here; that all lives in the generator.
 */
class ScribeBodyParameters
{
    public static function fromSchema(array $schema): array
    {
        $required = $schema['required'] ?? [];
        $parameters = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $parameters[$name] = [
                'name' => $name,
                'type' => self::scribeType($property),
                'required' => in_array($name, $required, true),
                'description' => $property['description'] ?? '',
                'example' => $property['examples'][0] ?? null,
                'nullable' => self::isNullable($property),
            ];
        }

        return $parameters;
    }

    protected static function scribeType(array $property): string
    {
        if (isset($property['$ref'])) {
            return 'object';
        }

        $type = $property['type'] ?? 'string';

        if (is_array($type)) {
            $type = collect($type)->first(fn ($t) => $t !== 'null') ?? 'string';
        }

        if ($type === 'array') {
            $itemRef = $property['items']['$ref'] ?? null;

            return $itemRef ? 'object[]' : 'string[]';
        }

        return match ($type) {
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            'object' => 'object',
            default => 'string',
        };
    }

    protected static function isNullable(array $property): bool
    {
        if (! empty($property['nullable'])) {
            return true;
        }

        $type = $property['type'] ?? null;

        return is_array($type) && in_array('null', $type, true);
    }
}
