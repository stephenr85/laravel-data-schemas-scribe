<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetController;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetData;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;

class BridgeTest extends TestCase
{
    private function endpoint(): ExtractedEndpointData
    {
        $route = $this->app['router']->post('widgets', [WidgetController::class, 'store']);

        return ExtractedEndpointData::fromRoute($route);
    }

    public function test_request_strategy_produces_body_params_and_stashes_schema(): void
    {
        $endpointData = $this->endpoint();

        $params = (new UseDataRequest(new DocumentationConfig([])))($endpointData);

        $this->assertSame(['name', 'status', 'owner'], array_keys($params));
        $this->assertTrue($params['name']['required']);
        $this->assertSame('string', $params['name']['type']);
        $this->assertSame('object', $params['owner']['type']);
        $this->assertTrue($params['owner']['nullable']);

        // Full schema stashed for the hook, with the enum hoisted to a $ref.
        $schema = $endpointData->custom['dataRequestSchema'];
        $this->assertSame('#/$defs/WidgetStatus', $schema['properties']['status']['$ref']);
        $this->assertArrayHasKey('WidgetStatus', $schema['$defs']);
    }

    public function test_response_strategy_produces_response_and_stashes_schema(): void
    {
        $endpointData = $this->endpoint();

        $responses = (new UseDataResponse(new DocumentationConfig([])))($endpointData);

        $this->assertSame(200, $responses[0]['status']);
        $this->assertIsArray(json_decode($responses[0]['content'], true));

        $stashed = $endpointData->custom['dataResponseSchemas'][0];
        $this->assertSame(200, $stashed['status']);
        $this->assertSame('WidgetData', $stashed['schema']['title']);
    }

    public function test_openapi_hook_injects_components_and_rewrites_refs(): void
    {
        $schema = (new JsonSchemaGenerator)->generate(new ReflectionClass(WidgetData::class));

        $endpoint = OutputEndpointData::create([
            'httpMethods' => ['POST'],
            'uri' => 'widgets',
            'custom' => [
                'dataRequestSchema' => $schema,
                'dataResponseSchemas' => [
                    ['status' => 200, 'schema' => $schema, 'description' => null],
                ],
            ],
        ]);

        $groups = [['description' => '', 'name' => 'Widgets', 'endpoints' => [$endpoint]]];
        $generator = new DataSchemaGenerator(new DocumentationConfig([]));

        // root(): every $def hoisted into components/schemas.
        $root = $generator->root([], $groups);
        $this->assertArrayHasKey('WidgetStatus', $root['components']['schemas']);
        $this->assertArrayHasKey('OwnerData', $root['components']['schemas']);

        // pathItem(): Scribe passes the bare operation object (not method-keyed);
        // request + response point at component refs, $defs stripped.
        $pathItem = $generator->pathItem(
            ['responses' => ['200' => []]],
            $groups,
            $endpoint,
        );

        $requestSchema = $pathItem['requestBody']['content']['application/json']['schema'];
        $this->assertArrayNotHasKey('$defs', $requestSchema);
        $this->assertSame('#/components/schemas/WidgetStatus', $requestSchema['properties']['status']['$ref']);

        $responseSchema = $pathItem['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('#/components/schemas/OwnerData', $responseSchema['properties']['owner']['$ref']);
    }
}
