<?php

namespace Rushing\LaravelDataSchemasScribe\Support;

/**
 * Build a representative example payload from a self-contained generator schema
 * (root + $defs), resolving internal $refs. Used to give Scribe's HTML/Postman
 * a response example; the OpenAPI schema itself comes from the generator.
 */
class SchemaExample
{
    public static function build(array $schema): mixed
    {
        $defs = $schema['$defs'] ?? [];

        return self::value($schema, $defs, []);
    }

    /**
     * @param  array<string, true>  $seen  guards against recursive $defs
     */
    protected static function value(array $node, array $defs, array $seen): mixed
    {
        if (isset($node['$ref'])) {
            $name = substr($node['$ref'], strlen('#/$defs/'));
            if (isset($seen[$name]) || ! isset($defs[$name])) {
                return null;
            }

            return self::value($defs[$name], $defs, $seen + [$name => true]);
        }

        if (array_key_exists('examples', $node)) {
            return $node['examples'][0] ?? null;
        }

        $type = $node['type'] ?? null;
        if (is_array($type)) {
            $type = collect($type)->first(fn ($t) => $t !== 'null') ?? 'string';
        }

        if ($type === 'object' || isset($node['properties'])) {
            $object = [];
            foreach ($node['properties'] ?? [] as $name => $property) {
                $object[$name] = self::value($property, $defs, $seen);
            }

            return $object;
        }

        if ($type === 'array') {
            $items = $node['items'] ?? [];

            return $items ? [self::value($items, $defs, $seen)] : [];
        }

        if (! empty($node['enum'])) {
            return $node['enum'][0];
        }

        return match ($type) {
            'integer', 'number' => 0,
            'boolean' => true,
            default => 'string',
        };
    }
}
