<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;

class StreamingWidgetController
{
    #[StreamsFromData('created', WidgetData::class, description: 'A widget came into being.')]
    #[StreamsFromData('owner_changed', [OwnerData::class, WidgetData::class])]
    public function watch() {}
}
