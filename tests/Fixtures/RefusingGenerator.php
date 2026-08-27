<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/**
 * Accepts nothing — a host whose configured chain has no member willing to build a given class.
 *
 * `ChainedGenerator::generate()` THROWS in that situation, where the hand-built
 * `JsonSchemaGenerator` these strategies used to construct would have generated regardless. This
 * fixture exists to hold the strategies to the guard: inside a Scribe strategy a throw is NOT loud
 * (Scribe catches per-route, prints only under `-v`, and drops the endpoint from the spec), so the
 * strategies must refuse by returning their own empty answer instead.
 */
class RefusingGenerator implements Generator
{
    public function canGenerate(ReflectionClass $class): bool
    {
        return false;
    }

    public function generate(ReflectionClass $class): array
    {
        return ['refused' => true];
    }

    public function forRequest(): static
    {
        return $this;
    }

    public function forResponse(): static
    {
        return $this;
    }

    public function forLlmStrict(): static
    {
        return $this;
    }

    public function schemaMode(string $mode): static
    {
        return $this;
    }
}
