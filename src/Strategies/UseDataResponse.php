<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Support\SchemaExample;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\LaravelData\Data;

/**
 * Drive an endpoint's response(s) from one or more Spatie Data classes.
 *
 * Stashes each response's full generator schema on `custom` for the
 * OpenApiGenerator hook (where it becomes a $ref-based schema), and returns a
 * Scribe response with an example payload for HTML/Postman. Schema generation is
 * delegated to the generator.
 *
 * @extends PhpAttributeStrategy<ResponseFromData>
 */
class UseDataResponse extends PhpAttributeStrategy
{
    protected static array $attributeNames = [ResponseFromData::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $specs = collect(array_merge($attributesOnMethod, $attributesOnController))
            ->map(fn (ResponseFromData $a) => ['dataClass' => $a->dataClass, 'status' => $a->status, 'description' => $a->description])
            ->all();

        if (empty($specs) && ($detected = $this->detectReturnType($endpointData->method))) {
            $specs[] = ['dataClass' => $detected, 'status' => 200, 'description' => null];
        }

        if (empty($specs)) {
            return [];
        }

        // Container-resolved so the host's `data-schemas.generators` LIST dispatches, rather than
        // hand-picking the default member — see {@see UseDataRequest} for the full reasoning and for
        // why a chain refusal must not be allowed to throw out of a Scribe strategy.
        //
        // Resolved once and put in response mode ONCE: every mode call returns a new cloned
        // instance, so this is a fresh resolve plus one clone, never a resolved singleton mutated in
        // the loop below.
        $generator = app(Generator::class)->forResponse();
        $stash = [];
        $responses = [];

        foreach ($specs as $spec) {
            if (! is_subclass_of($spec['dataClass'], Data::class)) {
                continue;
            }

            // Refusal takes the SAME branch a non-Data class already takes. A declared response the
            // chain will not build drops from this endpoint's response list; if that empties the
            // list, the `$stash === []` return below leaves the endpoint in the spec undocumented,
            // which is what Scribe swallowing a throw would not have done.
            $reflection = new ReflectionClass($spec['dataClass']);

            if (! $generator->canGenerate($reflection)) {
                continue;
            }

            $schema = $generator->generate($reflection);

            $stash[] = [
                'status' => $spec['status'],
                'schema' => $schema,
                'description' => $spec['description'],
            ];

            $responses[] = [
                'status' => $spec['status'],
                'content' => json_encode(SchemaExample::build($schema), JSON_PRETTY_PRINT),
                'description' => $spec['description'] ?? '',
            ];
        }

        if (empty($stash)) {
            return [];
        }

        $endpointData->custom['dataResponseSchemas'] = $stash;

        return $responses;
    }

    protected function detectReturnType(?ReflectionFunctionAbstract $method): ?string
    {
        $type = $method?->getReturnType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
            && is_subclass_of($type->getName(), Data::class)) {
            return $type->getName();
        }

        return null;
    }
}
