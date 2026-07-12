<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Support\SchemaExample;
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

        $generator = (new JsonSchemaGenerator)->forResponse();
        $stash = [];
        $responses = [];

        foreach ($specs as $spec) {
            if (! is_subclass_of($spec['dataClass'], Data::class)) {
                continue;
            }

            $schema = $generator->generate(new ReflectionClass($spec['dataClass']));

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
