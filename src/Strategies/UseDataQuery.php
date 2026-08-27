<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Attributes\QueryFromData;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\LaravelData\Data;

/**
 * Drive an endpoint's QUERY parameters from a Spatie Data class — the query-axis sibling of
 * {@see UseDataRequest}, and the one member of the `UseData*` family whose flat return IS the
 * published contract.
 *
 * ## Why this one is different
 *
 * {@see DataSchemaGenerator} overwrites `requestBody` and `responses` with the generator's full
 * `$ref`-based schema, so on those axes the flat parameter list only dresses Scribe's HTML and Postman
 * surfaces. It does **not** own OpenAPI's `parameters` array. Whatever this strategy returns reaches
 * the spec verbatim, which is why it dereferences (an enum property is a bare `$ref` until its `$defs`
 * entry is folded back in) and why it owns its own example policy: a leaf with no `#[Example]` and no
 * format/enum baseline emits `example: null`, never Scribe's `'No-example'` sentinel, which is
 * normalized in `GetParamsFromAttributeStrategy` — a class that extends `PhpAttributeStrategy` rather
 * than the reverse, so nothing here inherits it.
 *
 * ## Attribute-only, by design
 *
 * `UseDataRequest` falls back to the first typed `Data` parameter in the method signature. This one
 * does not: on a route carrying both a query DTO and a body DTO that fallback is a coin flip, and it
 * would silently document one axis as the other. Explicit attribute or nothing.
 *
 * The schema is stashed under its own `custom` key so a route may declare both axes without collision
 * — reusing `dataRequestSchema` would not merely overwrite it, it would grow a phantom `requestBody`
 * on a GET, because that is the key `DataSchemaGenerator::pathItem()` reads.
 *
 * @extends PhpAttributeStrategy<QueryFromData>
 */
class UseDataQuery extends PhpAttributeStrategy
{
    protected static array $attributeNames = [QueryFromData::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $attributes = array_merge($attributesOnMethod, $attributesOnController);

        $dataClass = $attributes[0]->dataClass ?? null;

        if (! $dataClass || ! is_subclass_of($dataClass, Data::class)) {
            return [];
        }

        // Container-resolved for the chain, guarded because the chain throws — see the long note on
        // the sibling {@see UseDataRequest}. The stakes are marginally higher on this axis: a
        // refusal that vanished the endpoint would take the whole `parameters` array with it, and
        // this strategy's flat return IS the published contract rather than HTML dressing.
        $reflection = new ReflectionClass($dataClass);
        $generator = app(Generator::class)->forRequest();

        if (! $generator->canGenerate($reflection)) {
            return [];
        }

        $schema = $generator->generate($reflection);

        $endpointData->custom['dataQuerySchema'] = $schema;

        return ScribeBodyParameters::fromSchema($schema, dereference: true);
    }
}
