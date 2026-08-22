<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

#[Description('The list contract for widgets.')]
class WidgetQueryData extends Data
{
    public function __construct(
        #[Description('Free-text search across the widget name.')]
        #[Example('grommet')]
        public ?string $search = null,
        // No #[Example] and no format/enum baseline — this is the leaf that pins the example policy.
        #[Description('Page of results to return.')]
        public ?int $page = null,
        // A backed enum: hoisted into $defs as a bare $ref, which is what `dereference` folds back in.
        #[Description('Only widgets in this state.')]
        public WidgetStatus|Optional|null $status = null,
    ) {}
}
