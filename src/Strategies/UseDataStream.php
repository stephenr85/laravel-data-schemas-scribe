<?php

namespace Rushing\LaravelDataSchemasScribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Support\SchemaExample;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

/**
 * Drive an SSE endpoint's documentation from its `#[StreamsFromData]` declarations
 * (particle-doctrine-followups #14 — until this strategy existed, no Scribe strategy read the stream
 * attribute, so every SSE endpoint was a silent hole in the spec).
 *
 * Merges repeated same-named declarations append-and-dedupe in declaration order (the attribute's
 * documented consumer convention — the exact loop `RouteReturnType::reflectDeclarations()` runs for the
 * TS manifest, so the spec and the client cannot disagree about an event union). Each event payload
 * class is generated through the SAME {@see JsonSchemaGenerator} the response strategies use, stashed
 * under `custom['dataStreamSchemas']` for {@see DataSchemaGenerator},
 * and rendered for HTML/Postman as one representative SSE frame per event.
 *
 * STRUCTURAL LIMIT, stated rather than silent: OpenAPI 3.1 has no first-class slot for a per-event
 * schema inside a `text/event-stream` body (that is AsyncAPI's territory). The document-assembly hook
 * therefore emits the union under the `x-sse-events` vendor extension on the `text/event-stream`
 * content entry — the event names and payload $refs are IN the spec, discoverable and diffable, just
 * not in a slot the spec format natively defines.
 *
 * @extends PhpAttributeStrategy<StreamsFromData>
 */
class UseDataStream extends PhpAttributeStrategy
{
    protected static array $attributeNames = [StreamsFromData::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $events = [];
        $descriptions = [];

        /** @var StreamsFromData $attribute */
        foreach (array_merge($attributesOnMethod, $attributesOnController) as $attribute) {
            foreach ($attribute->dataClasses as $dataClass) {
                if (! is_subclass_of($dataClass, Data::class)) {
                    continue;
                }

                if (! in_array($dataClass, $events[$attribute->event] ?? [], true)) {
                    $events[$attribute->event][] = $dataClass;
                }
            }

            if ($attribute->description !== null) {
                $descriptions[$attribute->event] = $attribute->description;
            }
        }

        if ($events === []) {
            return [];
        }

        $generator = (new JsonSchemaGenerator)->forResponse();
        $stash = [];
        $frames = '';

        foreach ($events as $event => $dataClasses) {
            $schemas = array_map(
                fn (string $dataClass) => $generator->generate(new ReflectionClass($dataClass)),
                $dataClasses,
            );

            $stash[] = [
                'event' => $event,
                'schemas' => $schemas,
                'description' => $descriptions[$event] ?? null,
            ];

            // One representative wire frame per event for the human-readable docs — the first
            // variant's example; further variants are visible in the x-sse-events union.
            $frames .= "event: {$event}\n"
                .'data: '.json_encode(SchemaExample::build($schemas[0]))."\n\n";
        }

        $endpointData->custom['dataStreamSchemas'] = $stash;

        return [[
            'status' => 200,
            'content' => rtrim($frames)."\n",
            'description' => 'text/event-stream — a typed event sequence ('.implode(', ', array_keys($events)).'); see x-sse-events in the OpenAPI spec for the payload union.',
        ]];
    }
}
