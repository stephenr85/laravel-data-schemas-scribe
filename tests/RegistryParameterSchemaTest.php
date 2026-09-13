<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\BaseGenerator;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;

class RegistryParameterSchemaTest extends TestCase
{
    public function test_path_enum_values_survive_the_base_writers_lossy_projection(): void
    {
        $config = new DocumentationConfig([]);
        $endpoint = new OutputEndpointData([
            'httpMethods' => ['GET'], 'uri' => 'things/{kind}',
            'urlParameters' => ['kind' => ['name' => 'kind', 'type' => 'string', 'required' => true, 'enumValues' => ['alpha', 'beta']]],
        ]);
        $parameters = (new BaseGenerator($config))->pathParameters([], [$endpoint], $endpoint->urlParameters);
        $result = (new DataSchemaGenerator($config))->pathParameters($parameters, [$endpoint], $endpoint->urlParameters);
        $this->assertSame(['alpha', 'beta'], $result['kind']['schema']['enum']);
    }

    public function test_empty_query_vocabulary_cannot_become_an_unconstrained_string(): void
    {
        $config = new DocumentationConfig([]);
        $endpoint = new OutputEndpointData([
            'httpMethods' => ['GET'], 'uri' => 'things',
            'metadata' => ['title' => 'List things', 'description' => '', 'groupName' => 'Things'],
            'queryParameters' => ['kind' => ['name' => 'kind', 'type' => 'string', 'required' => true]],
            'custom' => ['dataQuerySchema' => ['properties' => ['kind' => ['type' => 'string', 'not' => new \stdClass]]]],
        ]);
        $groups = [['name' => 'Things', 'description' => '', 'endpoints' => [$endpoint]]];
        $operation = (new BaseGenerator($config))->pathItem([], $groups, $endpoint);
        $result = (new DataSchemaGenerator($config))->pathItem($operation, $groups, $endpoint);
        $this->assertInstanceOf(\stdClass::class, $result['parameters'][0]['schema']['not']);
    }
}
