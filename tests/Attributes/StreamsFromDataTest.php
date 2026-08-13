<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Attributes;

use Attribute;
use ReflectionClass;
use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\OwnerData;
use Rushing\LaravelDataSchemasScribe\Tests\Fixtures\WidgetData;
use Rushing\LaravelDataSchemasScribe\Tests\TestCase;

/**
 * Pins the contract that makes #[StreamsFromData] interchangeable with a stream
 * operation's `output:` map — asserted here, where the attribute lives, rather
 * than only through a downstream consumer's suite.
 */
class StreamsFromDataTest extends TestCase
{
    public function test_single_class_string_normalizes_to_one_variant_list(): void
    {
        $single = new StreamsFromData('run_status', WidgetData::class);
        $list = new StreamsFromData('run_status', [WidgetData::class]);

        // Consumers never branch on the two spellings: both are a list.
        $this->assertSame([WidgetData::class], $single->dataClasses);
        $this->assertSame($single->dataClasses, $list->dataClasses);

        $union = new StreamsFromData('node_status', [WidgetData::class, OwnerData::class]);
        $this->assertSame([WidgetData::class, OwnerData::class], $union->dataClasses);
    }

    public function test_repeating_an_event_name_appends_variants_deduped_and_order_preserving(): void
    {
        $map = $this->mergedStreamMap(
            new ReflectionMethod(StreamingEndpointFixture::class, 'repeatedEventName'),
        );

        // Same-named declarations merge in declaration order, duplicates dropped —
        // the exact shape a stream particle operation declares in its `output:` slot.
        $this->assertSame([
            'node_status' => [WidgetData::class, OwnerData::class],
            'run_status' => [WidgetData::class],
        ], $map);
    }

    public function test_repeatability_permits_multiple_attributes_on_one_method(): void
    {
        $flags = (new ReflectionClass(StreamsFromData::class))
            ->getAttributes(Attribute::class)[0]
            ->newInstance()
            ->flags;

        $this->assertSame(Attribute::IS_REPEATABLE, $flags & Attribute::IS_REPEATABLE);

        $attributes = (new ReflectionMethod(StreamingEndpointFixture::class, 'distinctEventNames'))
            ->getAttributes(StreamsFromData::class);

        $this->assertCount(2, $attributes);

        // newInstance() is where PHP rejects an illegally repeated attribute, so
        // instantiating every declaration proves repeatability genuinely holds.
        $events = array_map(fn ($attribute) => $attribute->newInstance()->event, $attributes);
        $this->assertSame(['run_status', 'node_status'], $events);
    }

    public function test_description_round_trips(): void
    {
        $described = new StreamsFromData('run_status', WidgetData::class, description: 'Progress of the run.');

        $this->assertSame('Progress of the run.', $described->description);
        $this->assertNull((new StreamsFromData('run_status', WidgetData::class))->description);
    }

    /**
     * Fold repeated declarations into `event name → list<class-string>` exactly as the
     * attribute's docblock instructs consumers to: append in declaration order, dedupe.
     *
     * @return array<string, list<class-string>>
     */
    private function mergedStreamMap(ReflectionMethod $method): array
    {
        $map = [];

        foreach ($method->getAttributes(StreamsFromData::class) as $attribute) {
            $instance = $attribute->newInstance();

            $map[$instance->event] = array_values(array_unique(array_merge(
                $map[$instance->event] ?? [],
                $instance->dataClasses,
            )));
        }

        return $map;
    }
}

class StreamingEndpointFixture
{
    #[StreamsFromData('node_status', WidgetData::class)]
    #[StreamsFromData('node_status', [OwnerData::class, WidgetData::class])]
    #[StreamsFromData('run_status', WidgetData::class)]
    public function repeatedEventName(): void {}

    #[StreamsFromData('run_status', WidgetData::class)]
    #[StreamsFromData('node_status', OwnerData::class)]
    public function distinctEventNames(): void {}
}
