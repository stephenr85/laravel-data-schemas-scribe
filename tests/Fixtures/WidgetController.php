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

    /** A query DTO with no defaulted property — the mandatory-filter guard. */
    #[QueryFromData(MandatoryFilterQueryData::class)]
    public function mandatory(): void {}

    /** A query DTO whose properties declare non-null defaults — ticket 119's axis. */
    #[QueryFromData(DefaultedQueryData::class)]
    public function defaulted(): void {}

    /** Neither axis declared. */
    public function untouched(): void {}
}
