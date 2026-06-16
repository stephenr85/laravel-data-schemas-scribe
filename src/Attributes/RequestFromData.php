<?php

namespace Rushing\LaravelDataSchemasScribe\Attributes;

use Attribute;

/**
 * Declare the Spatie Data class that describes an endpoint's request body.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class RequestFromData
{
    /**
     * @param  class-string  $dataClass
     */
    public function __construct(
        public string $dataClass,
        public ?string $description = null,
    ) {}
}
