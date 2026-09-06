<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Schemastud\DataSchemas\Support\OpenApi;
use Spatie\LaravelData\Data;

uses(TestCase::class);

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

it('projects a nested addressable Data response into locally resolvable OpenAPI components', function (bool $strippedIdentity) {
    config(['data-schemas.base_uri' => 'https://app.splicewire.com/schemas']);
    $route = $this->app['router']->get('silos/{id}', [AddressableSiloController::class, 'show']);
    $extracted = ExtractedEndpointData::fromRoute($route);
    $config = new DocumentationConfig([]);
    (new UseDataResponse($config))($extracted);
    $schema = $extracted->custom['dataResponseSchemas'][0]['schema'];
    $id = 'https://app.splicewire.com/schemas/taxonomy/silo/1';
    expect($schema['properties']['data']['$ref'])->toBe($id)
        ->and($schema['$defs'])->toHaveKey($id);

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
    $operation = $generator->pathItem([], $groups, $endpoint);
    $ref = $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'];

    expect($ref)->toStartWith('#/components/schemas/');
    $name = substr($ref, strlen('#/components/schemas/'));
    expect($name)->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/')
        ->and($root['components']['schemas'])->toHaveKey($name)->toHaveCount(1)
        ->and($root['components']['schemas'][$name])->not->toHaveKeys(['$id', '$schema'])
        ->and($operation['responses']['200']['content']['application/json']['schema'])->not->toHaveKeys(['$id', '$schema']);
})->with([false, true]);

it('rejects component identity and shape collisions across endpoint schemas', function (bool $sameIdentity) {
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

    expect(fn () => $generator->root([], $groups))->toThrow(\InvalidArgumentException::class, 'component name collision');
})->with([false, true]);
