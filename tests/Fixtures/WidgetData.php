<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class WidgetData extends Data
{
    public function __construct(
        #[Max(120)]
        public string $name,
        public WidgetStatus $status,
        public ?OwnerData $owner,
    ) {}
}
