<?php

namespace Rushing\LaravelDataSchemasScribe\Support;

/**
 * Adapter glue: flatten a generator schema's top-level properties into Scribe's
 * flat parameter shape.
 *
 * On the BODY axis this feeds Scribe's HTML/Postman/try-it-out only — the OpenAPI
 * artifact gets the full $ref-based schema from the OpenApiGenerator hook. No
 * type→schema mapping happens here; that all lives in the generator.
 *
 * On the QUERY axis there is no such hook: `DataSchemaGenerator` does not own
 * OpenAPI's `parameters` array, so this flat shape reaches the spec verbatim and
 * is the whole contract. That is what `$dereference` exists for — see below.
 */
class ScribeBodyParameters
{
    /**
     * @param  bool  $dereference  fold a property's `$ref` back into it from the schema's own `$defs`
     *                             before mapping. OFF by default, because the body axis discards this
     *                             output in favour of the $ref-based schema and dereferencing there
     *                             would only flatten a shape the artifact renders properly.
     *
     * The query axis needs it: `JsonSchemaGenerator` hoists a backed enum into `$defs` and leaves the
     * property as a bare `{$ref: …}` carrying NO `type` of its own, so an enum query parameter would
     * otherwise document as `type: object` with its value set dropped — the declared contract failing
     * to reach the wire, which is the exact defect these strategies exist to close. Folding the def
     * back in recovers the scalar type, the `enum` values and the generator's enum-derived example.
     */
    public static function fromSchema(array $schema, bool $dereference = false): array
    {
        $required = $schema['required'] ?? [];
        $defs = $dereference ? ($schema['$defs'] ?? []) : [];
        $parameters = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $resolved = $dereference ? self::resolve($property, $defs) : $property;

            $parameter = [
                'name' => $name,
                'type' => self::scribeType($resolved),
                'required' => in_array($name, $required, true),
                'description' => $property['description'] ?? $resolved['description'] ?? '',
                // No `#[Example]` and no format/enum baseline means the generator emits no `examples`
                // at all, so this is null — Scribe's cue to document the parameter but leave it out of
                // the rendered example request. It is NEVER the `'No-example'` sentinel: that string is
                // normalized in `GetParamsFromAttributeStrategy`, which the `UseData*` family does not
                // extend, so returning it would ship it literally.
                'example' => $resolved['examples'][0] ?? null,
                'nullable' => self::isNullable($property) || self::isNullable($resolved),
            ];

            if (! empty($resolved['enum'])) {
                $parameter['enumValues'] = array_values($resolved['enum']);
            }

            $parameters[$name] = $parameter;
        }

        return $parameters;
    }

    /**
     * Fold a `$ref` (or an `anyOf` union carrying one alongside `null`) back into the property from the
     * document's own `$defs`. Non-refs pass through untouched.
     */
    protected static function resolve(array $property, array $defs): array
    {
        $ref = $property['$ref'] ?? null;

        if ($ref === null) {
            foreach ($property['anyOf'] ?? [] as $member) {
                if (isset($member['$ref'])) {
                    $ref = $member['$ref'];
                    break;
                }
            }
        }

        if (! is_string($ref) || ! str_starts_with($ref, '#/$defs/')) {
            return $property;
        }

        $def = $defs[substr($ref, strlen('#/$defs/'))] ?? null;

        if (! is_array($def)) {
            return $property;
        }

        // The property's own keywords (description, nullable) win over the def's; the pointer itself
        // must go, or `scribeType()` still sees a `$ref` and calls the folded enum an object.
        $resolved = $def + $property;
        unset($resolved['$ref'], $resolved['anyOf'], $resolved['title']);

        return $resolved;
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
