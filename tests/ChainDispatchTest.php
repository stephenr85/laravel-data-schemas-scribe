<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataQuery;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\NarrowWidgetGenerator;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\RefusingGenerator;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetController;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;

/**
 * The strategies dispatch over the host's configured generator LIST.
 *
 * `a6989da` fixed half of this defect — the strategies stopped building `JsonSchemaGenerator` BARE
 * and started passing `config('data-schemas')`, so they were no longer config-blind. What that left
 * is the other half: `data-schemas.generators` is a list, the dispatch rule ("the first member whose
 * `canGenerate()` accepts this class") lives only inside `ChainedGenerator`, and a site that
 * hand-builds `JsonSchemaGenerator` gets every config key EXCEPT that rule.
 *
 * `~/Herd/thingsontv` is the estate's only multi-generator host, configured
 * `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`. There the old code ran the plain generator over
 * a `Block` subclass and silently dropped its `#[NodeType]`/`#[NodeAttr]` bridging — a downgraded
 * document behind a successful extraction. These tests take that shape with a narrow fixture
 * generator configured first.
 */
class ChainDispatchTest extends TestCase
{
    private function endpoint(string $method, string $verb = 'post'): ExtractedEndpointData
    {
        $route = $this->app['router']->{$verb}('widgets', [WidgetController::class, $method]);

        return ExtractedEndpointData::fromRoute($route);
    }

    /** The thingsontv shape: narrow generator FIRST, and it is the one that wins for a class it accepts. */
    public function test_the_request_strategy_dispatches_to_the_narrow_generator_configured_first(): void
    {
        config()->set('data-schemas.generators', [NarrowWidgetGenerator::class, JsonSchemaGenerator::class]);

        $endpointData = $this->endpoint('store');

        (new UseDataRequest(new DocumentationConfig([])))($endpointData);

        // Hand-building JsonSchemaGenerator — what this site did before — produces a schema with no
        // marker and a `$defs` hoist. Dispatch is the only thing that puts the narrow member's
        // output here.
        $schema = $endpointData->custom['dataRequestSchema'];
        $this->assertSame(NarrowWidgetGenerator::class, $schema['x-generated-by'] ?? null);
    }

    /**
     * The mode call must reach the member that ultimately wins.
     *
     * `ChainedGenerator::forRequest()` sets the mode on EVERY member rather than on a pre-selected
     * one, because the member is chosen per class at `generate()` time. Resolving once and moding
     * once is also the only safe order — every mode call returns a new cloned instance, so a site
     * that resolved and then mutated would be reading a stale object.
     */
    public function test_the_request_mode_reaches_the_dispatched_member(): void
    {
        config()->set('data-schemas.generators', [NarrowWidgetGenerator::class, JsonSchemaGenerator::class]);

        $endpointData = $this->endpoint('store');

        (new UseDataRequest(new DocumentationConfig([])))($endpointData);

        $this->assertSame('request', $endpointData->custom['dataRequestSchema']['x-mode'] ?? null);
    }

    public function test_the_response_strategy_dispatches_and_carries_response_mode(): void
    {
        config()->set('data-schemas.generators', [NarrowWidgetGenerator::class, JsonSchemaGenerator::class]);

        $endpointData = $this->endpoint('store');

        (new UseDataResponse(new DocumentationConfig([])))($endpointData);

        $schema = $endpointData->custom['dataResponseSchemas'][0]['schema'];
        $this->assertSame(NarrowWidgetGenerator::class, $schema['x-generated-by'] ?? null);
        $this->assertSame('response', $schema['x-mode'] ?? null);
    }

    /**
     * ...and a class the narrow member refuses falls THROUGH to the general one, rather than being
     * handed to `generators[0]` because it happens to be first. `WidgetQueryData` is not
     * `WidgetData`, so the fixture refuses it.
     */
    public function test_a_class_the_narrow_generator_refuses_falls_through_to_the_general_one(): void
    {
        config()->set('data-schemas.generators', [NarrowWidgetGenerator::class, JsonSchemaGenerator::class]);

        $endpointData = $this->endpoint('index', 'get');

        $params = (new UseDataQuery(new DocumentationConfig([])))($endpointData);

        $schema = $endpointData->custom['dataQuerySchema'];
        $this->assertArrayNotHasKey('x-generated-by', $schema);
        $this->assertSame(['search', 'page', 'status'], array_keys($params));
    }

    /**
     * The hard requirement on this migration: `ChainedGenerator::generate()` throws when NO member
     * accepts, where the hand-built generator generated regardless.
     *
     * A throw here would not surface as an error. Scribe catches per-route, prints the exception
     * only under `-v`, and carries on — so the endpoint silently VANISHES from the spec, which is
     * precisely the failure `a6989da` was written to remove. The strategies therefore guard on
     * `canGenerate()` and take their own existing "nothing to contribute" branch, which leaves the
     * endpoint in the spec undocumented on that axis instead of deleting it.
     */
    public function test_a_chain_that_refuses_the_class_returns_empty_rather_than_throwing(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $endpointData = $this->endpoint('store');

        $params = (new UseDataRequest(new DocumentationConfig([])))($endpointData);

        $this->assertSame([], $params);
        $this->assertArrayNotHasKey('dataRequestSchema', $endpointData->custom);
    }

    public function test_a_refused_response_class_drops_from_the_response_list_rather_than_throwing(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $endpointData = $this->endpoint('store');

        $responses = (new UseDataResponse(new DocumentationConfig([])))($endpointData);

        $this->assertSame([], $responses);
        $this->assertArrayNotHasKey('dataResponseSchemas', $endpointData->custom);
    }

    public function test_a_refused_query_class_returns_empty_rather_than_throwing(): void
    {
        config()->set('data-schemas.generators', [RefusingGenerator::class]);

        $endpointData = $this->endpoint('index', 'get');

        $params = (new UseDataQuery(new DocumentationConfig([])))($endpointData);

        $this->assertSame([], $params);
        $this->assertArrayNotHasKey('dataQuerySchema', $endpointData->custom);
    }
}
