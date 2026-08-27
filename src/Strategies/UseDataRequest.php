<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\Generator;
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

        // Through the container, not `new JsonSchemaGenerator(config('data-schemas', []))`. That
        // construction was already correct on CONFIG (a6989da); what it still could not do is
        // dispatch. `data-schemas.generators` is a LIST, and the rule "the first member whose
        // `canGenerate()` accepts this class" lives only inside {@see ChainedGenerator} — so at
        // `~/Herd/thingsontv`, whose list is `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`,
        // hand-building the default one runs the PLAIN generator over a `Block` subclass and
        // silently drops its `#[NodeType]`/`#[NodeAttr]` bridging.
        //
        // GUARDED, and here the guard is load-bearing rather than defensive. The chain THROWS where
        // the hand-built generator generated regardless, and a throw raised inside a Scribe strategy
        // is not loud: Scribe catches per-route, prints only under `-v`, and carries on — the
        // endpoint simply VANISHES from the spec. That is the same silent amputation a6989da was
        // written to remove, so refusal degrades to this strategy's own existing
        // "nothing to contribute" answer, which leaves the endpoint documented without a body
        // rather than deleting it outright.
        $reflection = new ReflectionClass($dataClass);
        $generator = app(Generator::class)->forRequest();

        if (! $generator->canGenerate($reflection)) {
            return [];
        }

        $schema = $generator->generate($reflection);

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
