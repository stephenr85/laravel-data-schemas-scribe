<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Spatie\LaravelData\Data;

/**
 * Drive an endpoint's request body from a Spatie Data class.
 *
 * The full generator schema (root + $defs) is stashed on the endpoint's `custom`
 * bag for the OpenApiGenerator hook; the flat body parameters returned here feed
 * Scribe's HTML/Postman/try-it-out. All schema generation is delegated to the
 * generator — this strategy holds no type-mapping logic.
 *
 * @extends PhpAttributeStrategy<RequestFromData>
 */
class UseDataRequest extends PhpAttributeStrategy
{
    protected static array $attributeNames = [RequestFromData::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $attributes = array_merge($attributesOnMethod, $attributesOnController);

        $dataClass = $attributes[0]->dataClass ?? $this->detectFromSignature($endpointData->method);

        if (! $dataClass || ! is_subclass_of($dataClass, Data::class)) {
            return [];
        }

        $schema = (new JsonSchemaGenerator((array) config('data-schemas', [])))->forRequest()->generate(new ReflectionClass($dataClass));

        $endpointData->custom['dataRequestSchema'] = $schema;

        return ScribeBodyParameters::fromSchema($schema);
    }

    protected function detectFromSignature(?ReflectionFunctionAbstract $method): ?string
    {
        if (! $method) {
            return null;
        }

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Data::class)) {
                return $type->getName();
            }
        }

        return null;
    }
}
