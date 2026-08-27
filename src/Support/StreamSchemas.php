<?php

namespace Rushing\LaravelDataSchemasScribe\Support;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataStream;
use Schemastud\DataSchemas\Generators\Generator;
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
        // Container-resolved for chain dispatch, and resolved once then moded once — every mode call
        // clones, so nothing here mutates a shared instance. See {@see UseDataRequest} for why the
        // per-class `canGenerate()` guard below is not optional inside a Scribe strategy.
        $generator = app(Generator::class)->forResponse();
        $stash = [];
        $frames = '';

        foreach ($events as $event => $dataClasses) {
            $schemas = [];

            foreach ($dataClasses as $dataClass) {
                if (! is_subclass_of($dataClass, Data::class)) {
                    continue;
                }

                // A payload the chain refuses drops out of its event's variant union, exactly as a
                // non-Data payload already does; an event left with no variants is skipped by the
                // `$schemas === []` branch below.
                $reflection = new ReflectionClass($dataClass);

                if ($generator->canGenerate($reflection)) {
                    $schemas[] = $generator->generate($reflection);
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
