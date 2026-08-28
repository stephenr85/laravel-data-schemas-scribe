<?php

namespace Rushing\LaravelDataSchemasScribe\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Schemastud\DataSchemas\Support\OpenApi;

/**
 * The only stage where $ref / $defs / components survive.
 *
 * Scribe's parameter/response model is inline-only and can't express reuse,
 * recursion or vendor keywords. This document-assembly hook reads the generator
 * schemas the strategies stashed on each endpoint's `custom` bag and:
 *  - hoists every $def into the document's components/schemas (root), and
 *  - points each operation's request/response at the $ref-rewritten schema
 *    (pathItem), replacing the lossy inline schema Scribe would emit.
 */
class DataSchemaGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        // Our schemas use JSON Schema 2020-12 semantics ($ref siblings, type
        // arrays for null, examples) — i.e. OpenAPI 3.1, not Scribe's default 3.0.3.
        $root['openapi'] = '3.1.0';

        $schemas = [];

        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                foreach ($this->endpointSchemas($endpoint) as $schema) {
                    $converted = OpenApi::toOpenApiComponents($schema);
                    $schemas = array_merge($schemas, $converted['components']['schemas'] ?? []);
                }
            }
        }

        if (! empty($schemas)) {
            $root['components'] ??= [];
            $root['components']['schemas'] = array_merge($root['components']['schemas'] ?? [], $schemas);
        }

        return $root;
    }

    /**
     * Scribe passes the bare operation object here (keys like `requestBody`,
     * `responses`), not a method-keyed map — it wraps the result under the HTTP
     * method afterward. So we write the schema at the operation root.
     */
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $requestSchema = $endpoint->custom['dataRequestSchema'] ?? null;
        if ($requestSchema) {
            // A request DTO carrying an UploadedFile property surfaces as a
            // `format: binary` leaf; such a body is a file upload and must be
            // advertised as `multipart/form-data`, not `application/json`.
            $contentType = $this->hasBinaryProperty($requestSchema)
                ? 'multipart/form-data'
                : 'application/json';
            $pathItem['requestBody']['content'][$contentType]['schema'] = $this->operationSchema($requestSchema);
        }

        foreach ($endpoint->custom['dataResponseSchemas'] ?? [] as $response) {
            $status = (string) $response['status'];
            $pathItem['responses'][$status]['content']['application/json']['schema'] = $this->operationSchema($response['schema']);
        }

        if ($streams = $endpoint->custom['dataStreamSchemas'] ?? null) {
            // STRUCTURAL LIMIT (particle-doctrine-followups 14): OpenAPI 3.1 defines no per-event
            // schema slot inside a `text/event-stream` body — that is AsyncAPI's territory. Rather
            // than leave every SSE endpoint silently absent from the spec, the event union rides the
            // `x-sse-events` vendor extension: wire event name → payload schema ($ref-rewritten, a
            // oneOf when an event name covers several variants). Event names and payloads are IN the
            // document — discoverable and diffable — just not in a natively-defined slot.
            $pathItem['responses']['200']['content']['text/event-stream'] = [
                'schema' => [
                    'type' => 'string',
                    'description' => 'A server-sent event stream; each `data:` line carries one of the payloads declared in `x-sse-events`.',
                ],
                'x-sse-events' => $this->sseEvents($streams),
            ];
        }

        // The QUERY axis. `UseDataQuery` already stashed the generator schema the parameters were
        // flattened out of; this is the only place a keyword Scribe's `Parameter` cannot hold can be
        // put back on them. See applyQueryDefaults().
        if ($querySchema = $endpoint->custom['dataQuerySchema'] ?? null) {
            $pathItem = $this->applyQueryDefaults($pathItem, $querySchema);
        }

        return $pathItem;
    }

    /**
     * Put each query parameter's declared `default` onto its `schema.default`
     * (api-surface-coherence ticket 119).
     *
     * ## Why this cannot be fixed upstream, in the flat parameter shape
     *
     * `ScribeBodyParameters::fromSchema()` builds the same array on both axes, so a `default` key set
     * there would look symmetric — and be discarded. Scribe hydrates that array into
     * `Knuckles\Camel\Extraction\Parameter`, whose `BaseDTO` constructor assigns only keys for which
     * `property_exists()`; the class declares no `default` property, so the key is dropped before
     * `BaseGenerator::queryParamToOpenApiParameterObject()` ever sees it, and that method's
     * `generateFieldData()` builds a closed field shape with no `default` slot either. The flat shape
     * is not a lossy transport here, it is a closed one — so the keyword is re-read from the stashed
     * source schema rather than carried through a key that is dead at its own consumer.
     *
     * ## Why it lives on THIS generator rather than a third one
     *
     * `dataQuerySchema` is this package's stash, written by this package's strategy, and until now read
     * by nothing. A separate generator would have to be registered in every host's `scribe.generators`
     * config by hand — a config edit in every consuming app to fix a defect in this package. Extending
     * the hook the package already ships costs no host any change. It is the same shape
     * `RenderingDeliveryGenerator` (beam, ticket 32 §C) settled on: one document-assembly hook per
     * package, writing responses AND a parameter default, because the parameter default has nowhere
     * else to be written.
     *
     * PATH parameters are deliberately not touched — see the note in the query-axis test.
     *
     * @param  array<string, mixed>  $pathItem
     * @param  array<string, mixed>  $schema  the stashed generator schema
     * @return array<string, mixed>
     */
    protected function applyQueryDefaults(array $pathItem, array $schema): array
    {
        $defaults = [];

        // Keyed by the schema's own property key — which is the WIRE name (`JsonSchemaGenerator`
        // projects it, and emits `default` under that same key), and the wire name is what
        // `ScribeBodyParameters` published as the parameter name. The two agree by construction.
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (is_array($property) && array_key_exists('default', $property)) {
                $defaults[$name] = $property['default'];
            }
        }

        if ($defaults === []) {
            return $pathItem;
        }

        foreach ($pathItem['parameters'] ?? [] as $index => $parameter) {
            $name = $parameter['name'] ?? null;

            // `in` is asserted rather than assumed: this same array also carries the endpoint's
            // headers, and a header sharing a query parameter's name must not inherit its default.
            if (($parameter['in'] ?? null) !== 'query' || ! is_string($name)) {
                continue;
            }

            if (array_key_exists($name, $defaults)) {
                $pathItem['parameters'][$index]['schema']['default'] = $defaults[$name];
            }
        }

        return $pathItem;
    }

    /**
     * The `x-sse-events` map: wire event name → `{ data, description? }`, `data` being the
     * $ref-rewritten payload schema (a `oneOf` when the event name covers several variants).
     *
     * @param  array<int, array{event: string, schemas: array<int, array>, description: ?string}>  $streams
     * @return array<string, array<string, mixed>>
     */
    protected function sseEvents(array $streams): array
    {
        $events = [];

        foreach ($streams as $stream) {
            $schemas = array_map(fn (array $schema) => $this->operationSchema($schema), $stream['schemas']);

            $events[$stream['event']] = array_filter([
                'data' => count($schemas) === 1 ? $schemas[0] : ['oneOf' => array_values($schemas)],
                'description' => $stream['description'],
            ], fn ($value) => $value !== null);
        }

        return $events;
    }

    /**
     * A request schema is a file upload when any top-level property (or its
     * array items) is a `format: binary` leaf — the signal the Data→JSON-Schema
     * generator emits for an UploadedFile-typed property.
     */
    protected function hasBinaryProperty(array $schema): bool
    {
        foreach ($schema['properties'] ?? [] as $property) {
            if (($property['format'] ?? null) === 'binary') {
                return true;
            }
            if (($property['items']['format'] ?? null) === 'binary') {
                return true;
            }
        }

        return false;
    }

    /**
     * The per-operation schema: refs rewritten to components, $defs stripped
     * (they live globally in components/schemas), and the document-identity
     * keywords dropped.
     *
     * `$id` and `$schema` are properties of a STANDALONE schema document, and what this returns is
     * an EMBEDDED subschema — the object OpenAPI inlines at
     * `requestBody.content.<type>.schema`. Carrying them down is not merely untidy:
     * `JsonSchemaGenerator` mints a RELATIVE `$id` (`"WidgetData"`) whenever the host leaves
     * `data-schemas.base_uri` unset, and a relative `$id` on an embedded subschema RE-BASES `$ref`
     * resolution inside it. The sibling refs this very method rewrites are same-document fragments
     * (`#/components/schemas/WidgetStatus`), so a strict 2020-12 resolver would look for them
     * against `.../WidgetData` instead of the OpenAPI document — resolving to nothing.
     *
     * This was invisible for as long as the only test fed the hook a schema from a BARE
     * `new JsonSchemaGenerator`, which takes no config and therefore emits neither keyword. The
     * strategies in `src/Strategies` have always built theirs from `config('data-schemas')`, so a
     * real extraction run has always produced both — the fixture was the only thing that did not.
     */
    protected function operationSchema(array $schema): array
    {
        $converted = OpenApi::toOpenApiComponents($schema);
        unset($converted['components'], $converted['$id'], $converted['$schema']);

        return $converted;
    }

    /**
     * @return array<int, array> every stashed generator schema on the endpoint
     */
    protected function endpointSchemas(OutputEndpointData $endpoint): array
    {
        $schemas = [];

        if ($request = $endpoint->custom['dataRequestSchema'] ?? null) {
            $schemas[] = $request;
        }

        foreach ($endpoint->custom['dataResponseSchemas'] ?? [] as $response) {
            $schemas[] = $response['schema'];
        }

        foreach ($endpoint->custom['dataStreamSchemas'] ?? [] as $stream) {
            foreach ($stream['schemas'] as $schema) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }
}
