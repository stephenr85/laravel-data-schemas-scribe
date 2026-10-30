<?php

namespace Rushing\LaravelDataSchemasScribe\OpenApi;

use InvalidArgumentException;
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
    /** @var array<string, array<string, string>> Schema fingerprint => definition name => component name. */
    private array $componentAliases = [];

    /** @var array<string, array<string, array<string, string>>> Endpoint-set fingerprint => aliases. */
    private static array $rootAliasesByEndpointSet = [];

    public function root(array $root, array $groupedEndpoints): array
    {
        // Our schemas use JSON Schema 2020-12 semantics ($ref siblings, type
        // arrays for null, examples) — i.e. OpenAPI 3.1, not Scribe's default 3.0.3.
        $root['openapi'] = '3.1.0';

        $existing = $root['components']['schemas'] ?? [];
        $schemas = OpenApi::toOpenApiComponents(['$defs' => $existing])['components']['schemas'] ?? [];
        $identities = [];
        foreach ($existing as $name => $definition) {
            $identities[OpenApi::componentName((string) $name, $definition)] = $definition['$id'] ?? null;
        }
        $this->componentAliases = $this->componentAliases($groupedEndpoints, $schemas, $identities);
        self::$rootAliasesByEndpointSet[$this->endpointSetFingerprint($groupedEndpoints)] = $this->componentAliases;

        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                foreach ($this->endpointSchemas($endpoint) as $schema) {
                    $schema = $this->withComponentAliases($schema);
                    $converted = OpenApi::toOpenApiComponents($schema);
                    foreach ($schema['$defs'] ?? [] as $name => $definition) {
                        $component = OpenApi::componentName((string) $name, $definition);
                        $identity = $definition['$id'] ?? $name;
                        $body = $converted['components']['schemas'][$component];
                        if (isset($schemas[$component]) && (($identities[$component] !== null && $identities[$component] !== $identity) || $schemas[$component] != $body)) {
                            throw new InvalidArgumentException("OpenAPI component name collision [{$component}].");
                        }
                        $identities[$component] = $identity;
                        $schemas[$component] = $body;
                    }
                }
            }
        }

        if (! empty($schemas)) {
            $root['components'] ??= [];
            $root['components']['schemas'] = $schemas;
        }

        return $root;
    }

    /** Preserve finite path vocabularies that Scribe's base writer omits. */
    public function pathParameters(array $parameters, array $endpoints, array $urlParameters): array
    {
        foreach ($parameters as $index => $parameter) {
            $name = $parameter['name'] ?? $index;
            $values = $urlParameters[$name]->enumValues ?? [];
            if ($values !== []) {
                $parameters[$index]['schema']['enum'] = array_values($values);
            }
        }

        return $parameters;
    }

    /**
     * Scribe passes the bare operation object here (keys like `requestBody`,
     * `responses`), not a method-keyed map — it wraps the result under the HTTP
     * method afterward. So we write the schema at the operation root.
     */
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        // Scribe may instantiate each generator hook independently. Rebuild the deterministic
        // aliases from the complete endpoint set here so operation refs match the components
        // emitted by root(), even when this object did not run root() first.
        $endpointSet = $this->endpointSetFingerprint($groupedEndpoints);
        $this->componentAliases = self::$rootAliasesByEndpointSet[$endpointSet]
            ?? $this->componentAliases($groupedEndpoints, [], []);

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
        // put back on them. See applyQuerySchemaConstraints().
        if ($querySchema = $endpoint->custom['dataQuerySchema'] ?? null) {
            $pathItem = $this->applyQuerySchemaConstraints($pathItem, $querySchema);
        }

        // Full path schemas are carried separately because Parameter cannot express an empty
        // vocabulary. Per-operation overrides also preserve different constraints on shared paths.
        foreach ($endpoint->custom['dataPathParameterSchemas'] ?? [] as $name => $schema) {
            $parameter = $endpoint->urlParameters[$name] ?? null;
            if ($parameter === null) {
                continue;
            }

            $pathItem['parameters'] ??= [];
            $pathItem['parameters'] = array_values(array_filter(
                $pathItem['parameters'],
                fn (array $existing): bool => ($existing['in'] ?? null) !== 'path' || ($existing['name'] ?? null) !== $name,
            ));
            $pathItem['parameters'][] = [
                'in' => 'path',
                'name' => $name,
                'required' => true,
                'description' => $parameter->description,
                'schema' => $schema,
                ...($parameter->example === null ? [] : ['example' => $parameter->example]),
            ];
        }

        return $pathItem;
    }

    /**
     * Restore query schema keywords the flat parameter shape cannot carry: declared `default`
     * (api-surface-coherence ticket 119) and `not`, including an empty registry vocabulary, plus numeric `minimum`/`maximum`.
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
     * This helper touches query parameters only; path schemas are restored separately in pathItem().
     *
     * @param  array<string, mixed>  $pathItem
     * @param  array<string, mixed>  $schema  the stashed generator schema
     * @return array<string, mixed>
     */
    protected function applyQuerySchemaConstraints(array $pathItem, array $schema): array
    {
        $constraints = [];

        // Source properties and extracted parameter names share the declared wire name.
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (is_array($property)) {
                $constraints[$name] = array_intersect_key($property, array_flip([
                    'default', 'not', 'minimum', 'maximum',
                ]));
            }
        }

        foreach ($pathItem['parameters'] ?? [] as $index => $parameter) {
            $name = $parameter['name'] ?? null;

            // Headers and path parameters can share a query property's name without its constraints.
            if (($parameter['in'] ?? null) !== 'query' || ! is_string($name)) {
                continue;
            }

            foreach ($constraints[$name] ?? [] as $keyword => $value) {
                $pathItem['parameters'][$index]['schema'][$keyword] = $value;
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
        $schema = $this->withComponentAliases($schema);
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

    /** @param array<int, array{endpoints: array<int, OutputEndpointData>}> $groupedEndpoints */
    private function endpointSetFingerprint(array $groupedEndpoints): string
    {
        $schemas = [];
        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                foreach ($this->endpointSchemas($endpoint) as $schema) {
                    $schemas[] = $this->schemaFingerprint($schema);
                }
            }
        }

        return hash('sha256', json_encode($schemas, JSON_THROW_ON_ERROR));
    }

    /**
     * Give colliding, non-addressable definitions stable bridge-local component names.
     *
     * The Data schema keeps its declared short $defs names and ref graph. OpenAPI, however,
     * flattens definitions from every endpoint into one document-global namespace. Two legitimate
     * request/response projections (or two PHP classes with the same short name) can therefore have
     * one local name but different shapes. Split only those variants here; leave ordinary component
     * names byte-for-byte stable. An explicit absolute $id remains write-once and conflicting
     * shapes under the same id still fail closed.
     *
     * @param  array<int, array{description: string, name: string, endpoints: array<int, OutputEndpointData>}>  $groupedEndpoints
     * @param  array<string, array>  $existingSchemas
     * @param  array<string, string|null>  $existingIdentities
     * @return array<string, array<string, string>>
     */
    private function componentAliases(array $groupedEndpoints, array $existingSchemas, array $existingIdentities): array
    {
        $variants = [];
        $addressed = [];
        $schemaDefinitions = [];

        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                foreach ($this->endpointSchemas($endpoint) as $schema) {
                    $schemaFingerprint = $this->schemaFingerprint($schema);
                    foreach ($schema['$defs'] ?? [] as $name => $definition) {
                        $component = OpenApi::componentName((string) $name, $definition);
                        $identity = $definition['$id'] ?? $name;
                        $variant = hash('sha256', json_encode(
                            [$identity, $definition],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                        ));
                        $variants[$component][$variant] = true;
                        $schemaDefinitions[$schemaFingerprint][(string) $name] = $component;

                        if (isset($definition['$id'])) {
                            $idFingerprint = hash('sha256', json_encode(
                                $definition,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                            ));
                            if (isset($addressed[$component][$definition['$id']])
                                && $addressed[$component][$definition['$id']] !== $idFingerprint) {
                                throw new InvalidArgumentException("OpenAPI component name collision [{$component}].");
                            }
                            $addressed[$component][$definition['$id']] = $idFingerprint;
                        }
                    }
                }
            }
        }

        $aliases = [];
        foreach ($schemaDefinitions as $schemaFingerprint => $definitions) {
            $hasCollision = false;
            foreach ($definitions as $component) {
                if (count($variants[$component] ?? []) > 1) {
                    $hasCollision = true;
                    break;
                }
            }

            if ($hasCollision) {
                $aliases[$schemaFingerprint] = $this->namespacedDefinitions($definitions, $schemaFingerprint);
            }
        }

        // A previous OpenAPI root generator may already own a short component name. Preserve exact
        // identity/shape deduplication, but namespace the affected schema bundle if one of its
        // components would otherwise collide with that existing component.
        $this->componentAliases = $aliases;
        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                foreach ($this->endpointSchemas($endpoint) as $schema) {
                    $schemaFingerprint = $this->schemaFingerprint($schema);
                    if (isset($aliases[$schemaFingerprint])) {
                        continue;
                    }
                    $converted = OpenApi::toOpenApiComponents($this->withComponentAliases($schema));
                    foreach ($schema['$defs'] ?? [] as $name => $definition) {
                        $component = OpenApi::componentName((string) $name, $definition);
                        $identity = $definition['$id'] ?? $name;
                        if (! isset($existingSchemas[$component])) {
                            continue;
                        }

                        $body = $converted['components']['schemas'][$component] ?? null;
                        $existingIdentity = $existingIdentities[$component] ?? null;
                        if ($body === null || (($existingIdentity === null || $existingIdentity === $identity) && $existingSchemas[$component] == $body)) {
                            continue;
                        }

                        // A reused explicit absolute ID with a changed body is an identity error,
                        // not a naming collision. Keep the existing fail-closed behavior.
                        if (isset($definition['$id']) && $existingIdentity === $identity) {
                            continue;
                        }

                        $aliases[$schemaFingerprint] = $this->namespacedDefinitions($schemaDefinitions[$schemaFingerprint] ?? [], $schemaFingerprint);
                        $this->componentAliases = $aliases;
                        break;
                    }
                }
            }
        }

        return $aliases;
    }

    /** @param array<string, string> $definitions
     *  @return array<string, string>
     */
    private function namespacedDefinitions(array $definitions, string $schemaFingerprint): array
    {
        $aliases = [];
        foreach ($definitions as $name => $component) {
            $aliases[$name] = $component.'__'.hash('sha256', $schemaFingerprint."\0".$name);
        }

        return $aliases;
    }

    /** @param array<string, mixed> $schema */
    private function schemaFingerprint(array $schema): string
    {
        return hash('sha256', json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Apply the document-global component aliases while preserving the schema's local ref graph.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withComponentAliases(array $schema): array
    {
        $definitions = $schema['$defs'] ?? [];
        if ($definitions === [] || $this->componentAliases === []) {
            return $schema;
        }

        $schemaAliases = $this->componentAliases[$this->schemaFingerprint($schema)] ?? [];
        if ($schemaAliases === []) {
            return $schema;
        }

        $renames = [];
        $renamedDefinitions = [];
        foreach ($definitions as $name => $definition) {
            $component = $schemaAliases[(string) $name] ?? null;
            if ($component === null) {
                $renamedDefinitions[$name] = $definition;
                continue;
            }

            $escapedName = str_replace(['~', '/'], ['~0', '~1'], (string) $name);
            $escapedComponent = str_replace(['~', '/'], ['~0', '~1'], $component);
            $renames['#/$defs/'.$escapedName] = '#/$defs/'.$escapedComponent;
            $renames[(string) $name] = $component;
            $renamedDefinitions[$component] = $definition;
        }

        if ($renames === []) {
            return $schema;
        }

        $schema = $this->rewriteDefinitionRefs($schema, $renames);
        foreach ($renamedDefinitions as $name => $definition) {
            $renamedDefinitions[$name] = $this->rewriteDefinitionRefs($definition, $renames);
        }
        $schema['$defs'] = $renamedDefinitions;

        return $schema;
    }

    /** @param array<string, string> $renames */
    private function rewriteDefinitionRefs(mixed $node, array $renames): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && isset($renames[$value])) {
                $node[$key] = $renames[$value];
                continue;
            }

            $node[$key] = $this->rewriteDefinitionRefs($value, $renames);
        }

        return $node;
    }
}
