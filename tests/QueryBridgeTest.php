<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Illuminate\Http\Request;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataQuery;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetController;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetQueryData;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetStatus;

class QueryBridgeTest extends TestCase
{
    private function endpoint(string $method): ExtractedEndpointData
    {
        $route = $this->app['router']->get('widgets', [WidgetController::class, $method]);

        return ExtractedEndpointData::fromRoute($route);
    }

    public function test_query_strategy_documents_declared_prose_and_examples(): void
    {
        $endpointData = $this->endpoint('index');

        $params = (new UseDataQuery(new DocumentationConfig([])))($endpointData);

        $this->assertSame(['search', 'page', 'status'], array_keys($params));

        $this->assertSame('Free-text search across the widget name.', $params['search']['description']);
        $this->assertSame('grommet', $params['search']['example']);
        $this->assertSame('string', $params['search']['type']);
        $this->assertTrue($params['search']['nullable']);

        $this->assertSame('integer', $params['page']['type']);

        // A defaulted property is optional on the REQUEST axis, and `UseDataQuery` generates in
        // request mode — so all three of these publish as optional. `search` and `page` are
        // `?T $x = null`, `status` is an `Optional` union; none of them is a mandatory filter.
        $this->assertFalse($params['search']['required']);
        $this->assertFalse($params['page']['required']);
        $this->assertFalse($params['status']['required']);
    }

    /**
     * The other side of the same rule. Dropping DEFAULTED properties from `required` is not the same
     * as dropping nullable ones: a `?string $region` with no default is still mandatory, and a form
     * layer that relaxes on nullability rather than on has-default gets exactly this case backwards.
     */
    public function test_a_query_property_without_a_default_stays_required(): void
    {
        $endpointData = $this->endpoint('mandatory');

        $params = (new UseDataQuery(new DocumentationConfig([])))($endpointData);

        $this->assertTrue($params['tenant']['required']);
        $this->assertTrue($params['region']['required']);
        $this->assertTrue($params['region']['nullable']);
    }

    /**
     * The example policy, pinned. A leaf with no `#[Example]` and no format/enum baseline emits
     * `null` — NOT Scribe's `'No-example'` sentinel, which this strategy does not inherit the
     * normalization for and would therefore ship as a literal string.
     */
    public function test_a_leaf_with_no_example_emits_null_not_the_sentinel(): void
    {
        $params = (new UseDataQuery(new DocumentationConfig([])))($this->endpoint('index'));

        $this->assertArrayHasKey('example', $params['page']);
        $this->assertNull($params['page']['example']);
        $this->assertNotSame('No-example', $params['page']['example']);
    }

    /**
     * A backed enum is hoisted into `$defs` and left as a bare `$ref` carrying no type of its own.
     * Because `DataSchemaGenerator` does not own OpenAPI's `parameters` array, an undereferenced
     * property would publish `type: object` with the value set silently dropped.
     */
    public function test_an_enum_property_is_dereferenced_to_its_scalar_type_and_values(): void
    {
        $params = (new UseDataQuery(new DocumentationConfig([])))($this->endpoint('index'));

        $this->assertSame('string', $params['status']['type']);
        $this->assertSame(['on', 'off'], $params['status']['enumValues']);
        $this->assertSame('Only widgets in this state.', $params['status']['description']);
        $this->assertTrue($params['status']['nullable']);

        // No example even though the generator's own policy calls an enum baseline meaningful: it
        // infers examples per-property and skips anything carrying a `$ref`, so the `$defs` entry has
        // none to fold back. The value set is published as `enumValues`, which is the part a client
        // needs; synthesizing an example here would be this adapter second-guessing generator policy.
        $this->assertNull($params['status']['example']);
    }

    public function test_both_axes_on_one_route_stash_without_collision(): void
    {
        $endpointData = $this->endpoint('search');

        $query = (new UseDataQuery(new DocumentationConfig([])))($endpointData);
        $body = (new UseDataRequest(new DocumentationConfig([])))($endpointData);

        $this->assertSame(['search', 'page', 'status'], array_keys($query));
        $this->assertSame(['name', 'status', 'owner'], array_keys($body));

        $this->assertSame('WidgetQueryData', $endpointData->custom['dataQuerySchema']['title']);
        $this->assertSame('WidgetData', $endpointData->custom['dataRequestSchema']['title']);
    }

    /**
     * The query stash must not be the key `DataSchemaGenerator::pathItem()` reads, or a GET declaring
     * only a query DTO would grow a phantom request body.
     */
    public function test_a_query_only_route_grows_no_request_body(): void
    {
        $endpointData = $this->endpoint('index');
        (new UseDataQuery(new DocumentationConfig([])))($endpointData);

        $endpoint = OutputEndpointData::create([
            'httpMethods' => ['GET'],
            'uri' => 'widgets',
            'custom' => $endpointData->custom,
        ]);

        $pathItem = (new DataSchemaGenerator(new DocumentationConfig([])))->pathItem([], [], $endpoint);

        $this->assertArrayNotHasKey('requestBody', $pathItem);
    }

    public function test_a_route_declaring_neither_axis_is_unaffected(): void
    {
        $endpointData = $this->endpoint('untouched');

        $this->assertSame([], (new UseDataQuery(new DocumentationConfig([])))($endpointData));
        $this->assertArrayNotHasKey('dataQuerySchema', $endpointData->custom);
    }

    /**
     * The attribute's docblock claims Spatie hydrates a `Data` object from a GET query string, and
     * that a host may therefore make the documented class the actual input contract. That claim is
     * asserted here rather than assumed — the strategy reflects the class and never binds it, so
     * nothing else in this package would notice if it stopped being true.
     */
    public function test_spatie_hydrates_the_same_class_from_a_get_query_string(): void
    {
        $this->app['router']->get('probe', fn (Request $request) => WidgetQueryData::from($request)->toArray());

        $response = $this->get('probe?search=grommet&page=3&status=off');

        $response->assertOk();
        $this->assertSame([
            'search' => 'grommet',
            'page' => 3,
            'status' => 'off',
        ], $response->json());
    }

    /**
     * The other half of the same claim: type-hinting the class on the handler hydrates it too, so a
     * host may reflect and bind ONE class. Casting is real — `page=3` arrives as an int and
     * `status=off` as the backed enum, not as the raw query strings.
     */
    public function test_the_declared_class_also_hydrates_by_injection(): void
    {
        $this->app['router']->get('injected', fn (WidgetQueryData $query) => [
            'page' => $query->page,
            'status' => $query->status instanceof WidgetStatus ? $query->status->name : null,
        ]);

        $response = $this->get('injected?search=grommet&page=3&status=off');

        $response->assertOk();
        $this->assertSame(['page' => 3, 'status' => 'Off'], $response->json());
    }
}
