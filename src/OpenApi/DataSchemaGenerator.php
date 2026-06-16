<?php

namespace Rushing\LaravelDataSchemasScribe\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Rushing\LaravelDataSchemas\Support\OpenApi;

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

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $methods = array_map('strtolower', $endpoint->httpMethods ?? []);

        $requestSchema = $endpoint->custom['dataRequestSchema'] ?? null;
        if ($requestSchema) {
            $operationSchema = $this->operationSchema($requestSchema);
            foreach ($methods as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }
                $pathItem[$method]['requestBody']['content']['application/json']['schema'] = $operationSchema;
            }
        }

        foreach ($endpoint->custom['dataResponseSchemas'] ?? [] as $response) {
            $operationSchema = $this->operationSchema($response['schema']);
            $status = (string) $response['status'];
            foreach ($methods as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }
                $pathItem[$method]['responses'][$status]['content']['application/json']['schema'] = $operationSchema;
            }
        }

        return $pathItem;
    }

    /**
     * The per-operation schema: refs rewritten to components, $defs stripped
     * (they live globally in components/schemas).
     */
    protected function operationSchema(array $schema): array
    {
        $converted = OpenApi::toOpenApiComponents($schema);
        unset($converted['components']);

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

        return $schemas;
    }
}
