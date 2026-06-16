<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;

class WidgetController
{
    #[RequestFromData(WidgetData::class)]
    #[ResponseFromData(WidgetData::class, 200)]
    public function store(): void {}
}
