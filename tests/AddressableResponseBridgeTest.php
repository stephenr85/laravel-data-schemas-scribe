<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Schemastud\DataSchemas\Support\OpenApi;
use Spatie\LaravelData\Data;

class AddressableSiloData extends Data implements SchemaIdentity
{
    public function __construct(public string $id, public ?AddressableSiloData $parent = null) {}

    public static function schemaName(): string
    {
        return 'taxonomy/silo';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}

class AddressableSiloResponseData extends Data
{
    public function __construct(public AddressableSiloData $data) {}
}

class AddressableSiloController
{
    #[ResponseFromData(AddressableSiloResponseData::class)]
    public function show() {}
}

class AddressableResponseBridgeTest extends TestCase
{
    public static function booleanCases(): array
    {
        return ['false' => [false], 'true' => [true]];
    }

    #[DataProvider('booleanCases')]
    public function test_nested_addressable_responses_use_locally_resolvable_components(bool $strippedIdentity): void
    {
        config(['data-schemas.base_uri' => 'https://app.splicewire.com/schemas']);
        $route = $this->app['router']->get('silos/{id}', [AddressableSiloController::class, 'show']);
        $extracted = ExtractedEndpointData::fromRoute($route);
        $config = new DocumentationConfig([]);
        (new UseDataResponse($config))($extracted);
        $schema = $extracted->custom['dataResponseSchemas'][0]['schema'];
        $id = 'https://app.splicewire.com/schemas/taxonomy/silo/1';
        $this->assertSame($id, $schema['properties']['data']['$ref']);
        $this->assertArrayHasKey($id, $schema['$defs']);

        $endpoint = OutputEndpointData::create(['httpMethods' => ['GET'], 'uri' => 'silos/{id}', 'custom' => $extracted->custom]);
        $groups = [['description' => '', 'name' => 'Silos', 'endpoints' => [$endpoint]]];
        $generator = new DataSchemaGenerator($config);
        $existing = ['$schema' => 'https://json-schema.org/draft/2020-12/schema'] + $schema['$defs'][$id];
        if ($strippedIdentity) {
            $existing = OpenApi::toOpenApiComponents(['$defs' => ['AddressableSiloData' => $existing]])['components']['schemas']['AddressableSiloData'];
        }
        $root = $generator->root(['components' => ['schemas' => [
            'AddressableSiloData' => $existing,
        ]]], $groups);
        // Scribe can invoke root() and pathItem() on separate generator instances.
        $operation = (new DataSchemaGenerator($config))->pathItem([], $groups, $endpoint);
        $ref = $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'];

        $this->assertStringStartsWith('#/components/schemas/', $ref);
        $name = substr($ref, strlen('#/components/schemas/'));
        $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
        $this->assertArrayHasKey($name, $root['components']['schemas']);
        $this->assertCount(1, $root['components']['schemas']);
        foreach (['$id', '$schema'] as $keyword) {
            $this->assertArrayNotHasKey($keyword, $root['components']['schemas'][$name]);
            $this->assertArrayNotHasKey($keyword, $operation['responses']['200']['content']['application/json']['schema']);
        }
    }

    #[DataProvider('booleanCases')]
    public function test_component_identity_and_shape_collisions_are_rejected(bool $sameIdentity): void
    {
        $first = 'https://example.test/first/1';
        $second = $sameIdentity ? $first : 'https://example.test/second/1';
        $endpoints = [];
        foreach ([[$first, 'string'], [$second, 'integer']] as [$id, $type]) {
            $endpoints[] = OutputEndpointData::create([
                'httpMethods' => ['GET'], 'uri' => 'examples',
                'custom' => ['dataResponseSchemas' => [['status' => 200, 'schema' => [
                    'properties' => ['data' => ['$ref' => $id]],
                    '$defs' => [$id => ['$id' => $id, 'title' => 'SharedData', 'type' => 'object', 'properties' => ['id' => ['type' => $type]]]],
                ]]]],
            ]);
        }
        $groups = [['description' => '', 'name' => 'Examples', 'endpoints' => $endpoints]];
        $generator = new DataSchemaGenerator(new DocumentationConfig([]));

        if ($sameIdentity) {
            // An explicitly declared absolute $id remains a write-once schema identity.
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('component name collision');
            $generator->root([], $groups);

            return;
        }

        // Distinct addressable schemas with the same title need distinct OpenAPI component names.
        $root = $generator->root([], $groups);
        $this->assertCount(2, $root['components']['schemas']);
    }

    public function test_unaddressable_request_and_response_variants_keep_distinct_component_refs(): void
    {
        $first = [
            'type' => 'object',
            'properties' => ['breakpoint' => ['type' => 'string']],
            'required' => ['breakpoint'],
        ];
        $second = [
            'type' => 'object',
            'properties' => ['breakpoint' => ['type' => 'string'], 'computedLabel' => ['type' => 'string']],
            'required' => ['breakpoint', 'computedLabel'],
        ];
        $blockStyle = [
            'type' => 'object',
            'properties' => ['layers' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/StyleLayerData']]],
            'required' => ['layers'],
        ];
        $endpoints = [
            OutputEndpointData::create(['httpMethods' => ['POST'], 'uri' => 'styles', 'custom' => [
                'dataRequestSchema' => [
                    'type' => 'object',
                    'properties' => ['style' => ['$ref' => '#/$defs/StyleLayerData'], 'blockStyle' => ['$ref' => '#/$defs/BlockStyleData']],
                    'required' => ['style', 'blockStyle'],
                    '$defs' => ['StyleLayerData' => $first, 'BlockStyleData' => $blockStyle],
                ],
            ]]),
            OutputEndpointData::create(['httpMethods' => ['GET'], 'uri' => 'styles/{id}', 'custom' => [
                'dataResponseSchemas' => [['status' => 200, 'schema' => [
                    'type' => 'object',
                    'properties' => ['style' => ['$ref' => '#/$defs/StyleLayerData'], 'blockStyle' => ['$ref' => '#/$defs/BlockStyleData']],
                    'required' => ['style', 'blockStyle'],
                    '$defs' => ['StyleLayerData' => $second, 'BlockStyleData' => $blockStyle],
                ]]],
            ]]),
        ];
        $groups = [['description' => '', 'name' => 'Styles', 'endpoints' => $endpoints]];
        $generator = new DataSchemaGenerator(new DocumentationConfig([]));

        $root = $generator->root([], $groups);
        $request = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], $groups, $endpoints[0]);
        $response = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], $groups, $endpoints[1]);
        $requestRef = $request['requestBody']['content']['application/json']['schema']['properties']['style']['$ref'];
        $responseRef = $response['responses']['200']['content']['application/json']['schema']['properties']['style']['$ref'];
        $requestBlockRef = $request['requestBody']['content']['application/json']['schema']['properties']['blockStyle']['$ref'];
        $responseBlockRef = $response['responses']['200']['content']['application/json']['schema']['properties']['blockStyle']['$ref'];
        $requestName = substr($requestRef, strlen('#/components/schemas/'));
        $responseName = substr($responseRef, strlen('#/components/schemas/'));
        $requestBlockName = substr($requestBlockRef, strlen('#/components/schemas/'));
        $responseBlockName = substr($responseBlockRef, strlen('#/components/schemas/'));

        $this->assertNotSame($requestRef, $responseRef);
        $this->assertArrayHasKey($requestName, $root['components']['schemas']);
        $this->assertArrayHasKey($responseName, $root['components']['schemas']);
        $this->assertSame($first['properties'], $root['components']['schemas'][$requestName]['properties']);
        $this->assertSame($second['properties'], $root['components']['schemas'][$responseName]['properties']);
        $this->assertNotSame($requestBlockRef, $responseBlockRef);
        $this->assertSame($requestRef, $root['components']['schemas'][$requestBlockName]['properties']['layers']['items']['$ref']);
        $this->assertSame($responseRef, $root['components']['schemas'][$responseBlockName]['properties']['layers']['items']['$ref']);
    }

    public function test_operation_hook_reuses_root_alias_for_existing_component_collision(): void
    {
        $endpoint = OutputEndpointData::create(['httpMethods' => ['POST'], 'uri' => 'collisions', 'custom' => [
            'dataRequestSchema' => [
                'type' => 'object',
                'properties' => ['data' => ['$ref' => '#/$defs/CollisionData']],
                '$defs' => ['CollisionData' => [
                    'type' => 'object',
                    'properties' => ['value' => ['type' => 'string']],
                ]],
            ],
        ]]);
        $groups = [['description' => '', 'name' => 'Collisions', 'endpoints' => [$endpoint]]];
        $generator = new DataSchemaGenerator(new DocumentationConfig([]));
        $root = $generator->root(['components' => ['schemas' => ['CollisionData' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ]]]], $groups);

        // This matches Scribe's independent generator-hook lifecycle.
        $operation = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], $groups, $endpoint);
        $ref = $operation['requestBody']['content']['application/json']['schema']['properties']['data']['$ref'];
        $name = substr($ref, strlen('#/components/schemas/'));

        $this->assertNotSame('CollisionData', $name);
        $this->assertArrayHasKey($name, $root['components']['schemas']);
        $this->assertSame(['value' => ['type' => 'string']], $root['components']['schemas'][$name]['properties']);
    }
}
