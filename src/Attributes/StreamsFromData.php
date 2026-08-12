<?php

namespace Rushing\LaravelDataSchemasScribe\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declare the Spatie Data class(es) that describe ONE wire event an endpoint streams — the
 * event-oriented sibling of {@see ResponseFromData}.
 *
 * A streaming endpoint does not resolve to one response body; it emits a sequence of discrete typed
 * events under distinct wire names (SSE `event:` names). So the unit of declaration is
 * `event name → payload shape(s)`, not a single class: collapsing them would lose the event-name
 * dimension a client needs to narrow each listener to a concrete type.
 *
 * Repeatable, once per event name:
 *
 *     #[StreamsFromData('run_status', RunStatusEventData::class)]
 *     #[StreamsFromData('node_status', [NodeRunningEventData::class, NodeCompleteEventData::class])]
 *     public function stream() {}
 *
 * One event name may carry SEVERAL payload variants — a discriminated union, narrowed further by a
 * field on the DTO (e.g. `status`). Both spellings express that: pass a list to one attribute, or
 * repeat the attribute with the same event name (consumers merge same-named declarations in
 * declaration order). The resulting map is the same shape a stream operation declares in its
 * `output:` slot, so a declaration made either way generates identical client types.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class StreamsFromData
{
    /**
     * The payload variants for this event name, normalized to a list — a single class-string is a
     * one-variant union, so consumers never branch on the two spellings.
     *
     * @var list<class-string>
     */
    public array $dataClasses;

    /**
     * @param  string  $event  the wire event name (an SSE `event:` value, e.g. `node_status`)
     * @param  class-string|list<class-string>  $dataClass  one payload shape, or the variants of a
     *                                                      discriminated union under this one name
     */
    public function __construct(
        public string $event,
        string|array $dataClass,
        public ?string $description = null,
    ) {
        if ($event === '') {
            throw new InvalidArgumentException('#[StreamsFromData] requires a non-empty wire event name.');
        }

        $classes = array_values(is_array($dataClass) ? $dataClass : [$dataClass]);

        if ($classes === []) {
            throw new InvalidArgumentException(
                "#[StreamsFromData('{$event}')] declares no payload class; an event with no shape is not a ".
                'declaration. Omit the attribute instead.'
            );
        }

        foreach ($classes as $class) {
            if (! is_string($class) || $class === '') {
                throw new InvalidArgumentException(
                    "#[StreamsFromData('{$event}')] expects a Data class-string (or a list of them)."
                );
            }
        }

        $this->dataClasses = $classes;
    }
}
