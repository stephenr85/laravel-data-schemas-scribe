<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Rushing\LaravelDataSchemasScribe\Attributes\QueryFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;

class WidgetController
{
    #[RequestFromData(WidgetData::class)]
    #[ResponseFromData(WidgetData::class, 200)]
    public function store(): void {}

    #[QueryFromData(WidgetQueryData::class)]
    public function index(): void {}

    /**
     * Both axes on one route — the collision case: two schemas, two `custom` keys, and no phantom
     * request body on the query side.
     */
    #[QueryFromData(WidgetQueryData::class)]
    #[RequestFromData(WidgetData::class)]
    public function search(): void {}

    /** Neither axis declared. */
    public function untouched(): void {}
}
