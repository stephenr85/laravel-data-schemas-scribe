<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Extraction\Parameter;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\BaseGenerator;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataQuery;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\DefaultedQueryData;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetController;
use Schemastud\DataSchemas\Generators\Generator;

/**
 * A query parameter's declared `default` reaching the published spec (api-surface-coherence 119).
 *
 * The body axis has been served since `DataSchemaGenerator` started overwriting
 * `requestBody.content.*.schema` with the generator's own schema verbatim — a `default` emitted by the
 * generator arrives there with no work at all. The query axis is not the same shape: OpenAPI's
 * `parameters` array is built by Scribe in vendor code, out of a DTO that has no `default` field, so
 * the keyword had nowhere to ride. These tests pin BOTH halves — that the flat shape genuinely cannot
 * carry it, and that the document-assembly hook puts it back.
 */
class QueryParameterDefaultTest extends TestCase
{
    private function endpoint(string $method): ExtractedEndpointData
    {
        $route = $this->app['router']->get('widgets', [WidgetController::class, $method]);

        return ExtractedEndpointData::fromRoute($route);
    }

    /**
     * Run the REAL writing pipeline for one endpoint: Scribe's own `BaseGenerator` builds the
     * `parameters` array exactly as it does in production, then this package's hook runs after it, in
     * the order `scribe.openapi.generators` puts them.
     *
     * @return array<string, mixed> the assembled path item
     */
    private function pathItem(string $method): array
    {
        $extracted = $this->endpoint($method);

        $config = new DocumentationConfig([]);

        $queryParameters = (new UseDataQuery($config))($extracted) ?: [];
        $bodyParameters = (new UseDataRequest($config))($extracted) ?: [];

        $endpoint = OutputEndpointData::create([
            'httpMethods' => ['GET'],
            'uri' => 'widgets',
            'metadata' => ['title' => 'Widgets', 'description' => '', 'groupName' => 'Widgets'],
            'queryParameters' => $queryParameters,
            'bodyParameters' => $bodyParameters,
            'custom' => $extracted->custom,
        ]);

        $groups = [['name' => 'Widgets', 'description' => '', 'endpoints' => [$endpoint]]];

        $pathItem = (new BaseGenerator($config))->pathItem([], $groups, $endpoint);

        return (new DataSchemaGenerator($config))->pathItem($pathItem, $groups, $endpoint);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parameter(array $pathItem, string $name): ?array
    {
        foreach ($pathItem['parameters'] ?? [] as $parameter) {
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * ⚠️ The premise, asserted rather than assumed. `Parameter` extends `BaseDTO`, whose constructor
     * assigns only keys for which `property_exists()`, and it declares no `default` property. So a
     * `default` set in `ScribeBodyParameters` is dropped before Scribe's own generator sees it — the
     * flat parameter shape is a CLOSED transport, not a lossy one.
     *
     * This is why the fix re-reads the stashed source schema instead of adding a key upstream: that
     * key would be dead at its immediate consumer AND would never arrive.
     */
    public function test_scribes_parameter_dto_cannot_carry_a_default_at_all(): void
    {
        $this->assertFalse(
            property_exists(Parameter::class, 'default'),
            'Scribe grew a `default` field; ticket 119 fix should be revisited against it.'
        );

        $parameter = new Parameter([
            'name' => 'page',
            'type' => 'integer',
            'default' => 1,
        ]);

        $this->assertArrayNotHasKey('default', $parameter->toArray());
    }

    /**
     * And the same at the layer below it: Scribe's field-shape builder emits a closed set of keywords,
     * `default` not among them. Both halves of the drop are pinned so a future Scribe upgrade that
     * fixes either one fails here loudly rather than leaving a redundant hook in place.
     */
    public function test_scribes_field_shape_has_no_default_slot(): void
    {
        $schema = (new BaseGenerator(new DocumentationConfig([])))->generateFieldData([
            'name' => 'page',
            'type' => 'integer',
            'example' => 3,
            'default' => 1,
        ]);

        $this->assertArrayNotHasKey('default', $schema);
    }

    public function test_a_declared_query_default_reaches_the_parameters_schema(): void
    {
        $pathItem = $this->pathItem('defaulted');

        $this->assertSame(1, $this->parameter($pathItem, 'page')['schema']['default']);
    }

    /**
     * The sentinel trap. `false` is a declared default; a truthiness check anywhere on the path drops
     * it, and the parameter then publishes as having none — indistinguishable from a property that
     * declared nothing.
     */
    public function test_a_falsy_declared_default_is_published_not_swallowed(): void
    {
        $pathItem = $this->pathItem('defaulted');

        $this->assertSame(false, $this->parameter($pathItem, 'archived')['schema']['default']);
    }

    /**
     * A backed enum is hoisted into `$defs`, so its property carries the `default` as a SIBLING of a
     * bare `$ref` rather than next to a `type`. The published parameter keeps its dereferenced scalar
     * type and value set (that is `ScribeBodyParameters`' doing) and now also its default.
     */
    public function test_an_enum_default_rides_alongside_the_ref_and_still_lands(): void
    {
        $parameter = $this->parameter($this->pathItem('defaulted'), 'status');

        $this->assertSame('on', $parameter['schema']['default']);
        $this->assertSame('string', $parameter['schema']['type']);
        $this->assertSame(['on', 'off'], $parameter['schema']['enum']);
    }

    /**
     * A null default publishes nothing — the generator suppresses it (ticket 72 Q2) and this hook has
     * nothing to copy. Asserted here too, because the copy is a separate step that could reintroduce
     * `default: null` by reading a key that was never set.
     */
    public function test_a_null_default_publishes_no_keyword(): void
    {
        $parameter = $this->parameter($this->pathItem('defaulted'), 'search');

        $this->assertNotNull($parameter);
        $this->assertArrayNotHasKey('default', $parameter['schema']);
    }

    /**
     * A query DTO whose properties declare no non-null default leaves every parameter untouched — the
     * hook must be a no-op, not a writer of empty keys. This is the shape of the estate's only live
     * `#[QueryFromData]` route today.
     */
    public function test_a_query_dto_with_no_declared_defaults_changes_nothing(): void
    {
        $pathItem = $this->pathItem('index');

        foreach ($pathItem['parameters'] as $parameter) {
            $this->assertArrayNotHasKey('default', $parameter['schema']);
        }
    }

    /**
     * A route declaring no query axis at all never reaches the walk.
     */
    public function test_a_route_with_no_query_stash_is_untouched(): void
    {
        $pathItem = $this->pathItem('untouched');

        $this->assertSame([], $pathItem['parameters']);
    }

    /**
     * ⚠️ 119's acceptance: the BODY and RESPONSE axes must not move. They are already served by the
     * `$ref`-based schema this same hook writes, and a parameters walk that reached into
     * `requestBody`/`responses` would regress a working axis to fix a broken one.
     */
    public function test_the_body_and_response_axes_are_untouched_by_the_query_walk(): void
    {
        $extracted = $this->endpoint('store');
        $config = new DocumentationConfig([]);

        (new UseDataRequest($config))($extracted);
        (new UseDataResponse($config))($extracted);

        $endpoint = OutputEndpointData::create([
            'httpMethods' => ['POST'],
            'uri' => 'widgets',
            'custom' => $extracted->custom,
        ]);

        $generator = new DataSchemaGenerator($config);

        // The same document, with and without a query stash on the endpoint. The request body and the
        // responses must be byte-identical between them.
        $without = $generator->pathItem([], [], $endpoint);

        $endpoint->custom['dataQuerySchema'] = app(Generator::class)
            ->forRequest()
            ->generate(new \ReflectionClass(DefaultedQueryData::class));

        $with = $generator->pathItem([], [], $endpoint);

        $this->assertSame($without['requestBody'], $with['requestBody']);
        $this->assertSame($without['responses'], $with['responses']);
    }

    /**
     * The body axis genuinely carries its own `default` already — via the schema, not via a parameter.
     * Asserted so "the asymmetry is closed" is a measured claim rather than a reading of the code.
     */
    public function test_the_body_axis_carries_its_default_in_the_schema_without_this_hook(): void
    {
        $schema = app(Generator::class)
            ->forRequest()
            ->generate(new \ReflectionClass(DefaultedQueryData::class));

        $this->assertSame(1, $schema['properties']['page']['default']);

        // ...and the flat shape the SAME schema flattens to does not, which is the asymmetry 119 names.
        $flat = ScribeBodyParameters::fromSchema($schema, dereference: true);

        $this->assertArrayNotHasKey('default', $flat['page']);
    }

    /**
     * ⚠️ PATH parameters: ruled OUT, not overlooked (119's fourth acceptance item).
     *
     * `BaseGenerator::urlParamToOpenApiParameterObject()` builds `schema` as `['type' => …]` only, so
     * structurally the same hole exists. It is not a defect on this axis:
     *
     *  1. OpenAPI requires `required: true` on every path parameter — Scribe hardcodes it — and the
     *     spec says `default` SHOULD NOT be used with a required parameter, since there is no
     *     omitted case for it to fill.
     *  2. Nothing sources a path parameter from a `Data` class default in the first place. The
     *     `UseData*` family declares no URL-parameter strategy at all; path parameters come from route
     *     segments, and their "defaults" are route-model-binding values, not declared property
     *     defaults.
     *
     * So there is no declared `default` to lose on that axis. Pinned as an assertion so a future URL
     * strategy in this package fails here and forces the question to be re-asked.
     */
    public function test_no_url_parameter_strategy_exists_to_carry_a_declared_default(): void
    {
        $strategies = array_map(
            fn (string $file) => basename($file, '.php'),
            glob(__DIR__.'/../src/Strategies/*.php') ?: []
        );

        sort($strategies);

        $this->assertSame(['UseDataQuery', 'UseDataRequest', 'UseDataResponse', 'UseDataStream'], $strategies);
    }
}
