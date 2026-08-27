<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/**
 * A NARROW generator: it accepts exactly one class and refuses everything else.
 *
 * Stands in for `~/Herd/thingsontv`'s `BlockJsonSchemaGenerator`, which only takes `Block`
 * subclasses and bridges their `#[NodeType]`/`#[NodeAttr]` declarations. The estate's only
 * multi-generator host configures `[BlockJsonSchemaGenerator, JsonSchemaGenerator]` — narrow FIRST —
 * so a consumer that hand-builds `JsonSchemaGenerator` runs the wrong one over a Block and silently
 * emits an unbridged document behind an HTTP 200.
 *
 * Marks its output (`x-generated-by`) and records the mode it was put in, so a test can tell WHICH
 * member produced a schema and whether the mode call reached it, rather than inferring either.
 */
class NarrowWidgetGenerator implements Generator
{
    public string $mode = 'collapsed';

    public function canGenerate(ReflectionClass $class): bool
    {
        return $class->getName() === WidgetData::class;
    }

    public function generate(ReflectionClass $class): array
    {
        return [
            'x-generated-by' => static::class,
            'x-mode' => $this->mode,
            'type' => 'object',
            'title' => $class->getShortName(),
            'properties' => ['name' => ['type' => 'string']],
        ];
    }

    public function forRequest(): static
    {
        return $this->withMode('request');
    }

    public function forResponse(): static
    {
        return $this->withMode('response');
    }

    public function forLlmStrict(): static
    {
        return $this->withMode('llm_strict');
    }

    public function schemaMode(string $mode): static
    {
        return $this->withMode($mode);
    }

    /** Every mode call CLONES, exactly as JsonSchemaGenerator does — never mutates in place. */
    protected function withMode(string $mode): static
    {
        $clone = clone $this;
        $clone->mode = $mode;

        return $clone;
    }
}
