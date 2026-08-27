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
use Schemastud\DataSchemas\Generators\Generator;

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
        // The subject here is DataSchemaGenerator, the OpenAPI hook — the JSON Schema is only its
        // INPUT. Building it bare fed the hook a document no host produces: a bare generator takes
        // no config, so it emits neither `$schema` nor `$id`, while the strategies in `src/` build
        // theirs from `config('data-schemas')` and a real extraction run therefore hands the hook
        // both. Resolving through the container closes that gap, so the fixture is the shape the
        // hook actually meets.
        $generator = app(Generator::class);
        $reflection = new ReflectionClass(WidgetData::class);

        // Asserted rather than assumed: ChainedGenerator::generate() THROWS when no configured
        // generator accepts the class, where the bare generator this replaced generated regardless.
        $this->assertTrue($generator->canGenerate($reflection));

        $schema = $generator->generate($reflection);
        $this->assertArrayHasKey('$id', $schema);

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

        // An embedded subschema carries no document identity. `$id` is relative whenever the host
        // leaves `base_uri` unset, and a relative `$id` re-bases the fragment `$ref` on the line
        // above. Only reachable as an assertion because the fixture above is now host-shaped.
        $this->assertArrayNotHasKey('$id', $requestSchema);
        $this->assertArrayNotHasKey('$schema', $requestSchema);

        $responseSchema = $pathItem['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('#/components/schemas/OwnerData', $responseSchema['properties']['owner']['$ref']);
    }
}
