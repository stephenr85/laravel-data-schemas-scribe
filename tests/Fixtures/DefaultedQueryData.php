<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * A query contract whose properties declare REAL defaults — the fixture for ticket 119.
 *
 * `WidgetQueryData` cannot serve here: every one of its properties defaults to `null`, and
 * `JsonSchemaGenerator::declaredDefault()` suppresses a null default outright (ticket 72 Q2), so it
 * publishes no `default` keyword at all and would assert nothing.
 *
 * The four properties are the four cases the emit has to get right on this axis:
 * a plain scalar, a FALSY scalar (the sentinel trap), a backed enum sitting behind a `$ref`, and a
 * null default that must stay absent.
 */
class DefaultedQueryData extends Data
{
    public function __construct(
        #[Description('Page of results to return.')]
        public int $page = 1,
        // `false` is a declared default and must publish. A `?:`-style truthiness check anywhere on
        // the path loses it, and the parameter then documents as having no default at all.
        #[Description('Include archived widgets.')]
        public bool $archived = false,
        // Hoisted into `$defs`; the `default` rides as a sibling of the `$ref`, which is exactly the
        // shape a naive "only properties with a type" walk would skip.
        #[Description('Only widgets in this state.')]
        public WidgetStatus $status = WidgetStatus::On,
        // Declares a default, but a null one — nothing may be published for it.
        #[Description('Free-text search across the widget name.')]
        public ?string $search = null,
    ) {}
}
