<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataStream;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\StreamingWidgetController;

/**
 * particle-doctrine-followups #14 — streams reach the OpenAPI leg. Before UseDataStream existed, no
 * Scribe strategy read `#[StreamsFromData]`, so every SSE endpoint was a silent hole in the spec.
 * OpenAPI 3.1 has no native per-event schema slot for `text/event-stream`, so the event union rides
 * the `x-sse-events` vendor extension — present and diffable, never silently absent.
 */
class StreamsBridgeTest extends TestCase
{
    private function endpoint(): ExtractedEndpointData
    {
        $route = $this->app['router']->get('widgets/watch', [StreamingWidgetController::class, 'watch']);

        return ExtractedEndpointData::fromRoute($route);
    }

    public function test_stream_strategy_stashes_the_event_union_and_renders_sse_frames(): void
    {
        $endpointData = $this->endpoint();

        $responses = (new UseDataStream(new DocumentationConfig([])))($endpointData);

        // The stash: one entry per wire event, declaration order, variants appended.
        $stash = $endpointData->custom['dataStreamSchemas'];
        $this->assertSame(['created', 'owner_changed'], array_column($stash, 'event'));
        $this->assertSame('A widget came into being.', $stash[0]['description']);
        $this->assertCount(1, $stash[0]['schemas']);
        $this->assertCount(2, $stash[1]['schemas']);
        $this->assertSame('WidgetData', $stash[0]['schemas'][0]['title']);

        // The human-readable response: one representative SSE frame per event.
        $this->assertSame(200, $responses[0]['status']);
        $this->assertStringContainsString("event: created\ndata: {", $responses[0]['content']);
        $this->assertStringContainsString("event: owner_changed\ndata: {", $responses[0]['content']);
        $this->assertStringContainsString('text/event-stream', $responses[0]['description']);
    }

    public function test_openapi_hook_emits_the_event_stream_content_with_x_sse_events(): void
    {
        $endpointData = $this->endpoint();
        (new UseDataStream(new DocumentationConfig([])))($endpointData);

        $endpoint = OutputEndpointData::create([
            'httpMethods' => ['GET'],
            'uri' => 'widgets/watch',
            'custom' => ['dataStreamSchemas' => $endpointData->custom['dataStreamSchemas']],
        ]);

        $groups = [['description' => '', 'name' => 'Widgets', 'endpoints' => [$endpoint]]];
        $generator = new DataSchemaGenerator(new DocumentationConfig([]));

        // root(): the event payload $defs hoist into components/schemas like any response schema.
        $root = $generator->root([], $groups);
        $this->assertArrayHasKey('WidgetStatus', $root['components']['schemas']);
        $this->assertArrayHasKey('OwnerData', $root['components']['schemas']);

        // pathItem(): the text/event-stream content entry carries the union under x-sse-events.
        $pathItem = $generator->pathItem(['responses' => ['200' => []]], $groups, $endpoint);
        $content = $pathItem['responses']['200']['content']['text/event-stream'];

        $this->assertSame('string', $content['schema']['type']);

        $events = $content['x-sse-events'];
        $this->assertSame(['created', 'owner_changed'], array_keys($events));
        $this->assertSame('A widget came into being.', $events['created']['description']);
        // Single-variant event: the payload schema directly, $defs stripped and refs rewritten.
        $this->assertSame('#/components/schemas/WidgetStatus', $events['created']['data']['properties']['status']['$ref']);
        $this->assertArrayNotHasKey('$defs', $events['created']['data']);
        // Multi-variant event: a oneOf across the variants, declaration order preserved.
        $this->assertCount(2, $events['owner_changed']['data']['oneOf']);
        $this->assertArrayNotHasKey('description', $events['owner_changed']);
    }
}
