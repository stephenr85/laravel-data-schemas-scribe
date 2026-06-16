<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Spatie\LaravelData\Data;

class OwnerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
