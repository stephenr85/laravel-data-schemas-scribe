<?php

namespace Rushing\LaravelDataSchemasScribe\Support;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataStream;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;

/**
 * Shared stream-schema stashing for every strategy that documents an SSE endpoint — the attribute
 * strategy in this package ({@see UseDataStream}) and any
 * route-driven platform strategy resolving the SAME event map from a Stream operation's `output:` slot.
 * One implementation means the `custom['dataStreamSchemas']` shape and the rendered SSE frames cannot
 * drift between declaration sites.
 */
class StreamSchemas
{
    /**
     * Generate, stash, and render an event map. Returns the flat Scribe response list (one
     * representative SSE frame per event), or `[]` when no event carries a Data payload.
     *
     * @param  array<string, list<class-string>>  $events  wire event name → payload Data classes
     * @param  array<string, string>  $descriptions  optional per-event descriptions
     * @return array<int, array<string, mixed>>
     */
    public static function stash(ExtractedEndpointData $endpointData, array $events, array $descriptions = []): array
    {
        $generator = (new JsonSchemaGenerator)->forResponse();
        $stash = [];
        $frames = '';

        foreach ($events as $event => $dataClasses) {
            $schemas = [];

            foreach ($dataClasses as $dataClass) {
                if (is_subclass_of($dataClass, Data::class)) {
                    $schemas[] = $generator->generate(new ReflectionClass($dataClass));
                }
            }

            if ($schemas === []) {
                continue;
            }

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

        if ($stash === []) {
            return [];
        }

        $endpointData->custom['dataStreamSchemas'] = $stash;

        return [[
            'status' => 200,
            'content' => rtrim($frames)."\n",
            'description' => 'text/event-stream — a typed event sequence ('.implode(', ', array_column($stash, 'event')).'); see x-sse-events in the OpenAPI spec for the payload union.',
        ]];
    }
}
