<?php

namespace Rushing\LaravelDataSchemasScribe\Attributes;

use Attribute;

/**
 * Declare a Spatie Data class that describes an endpoint's response body for a
 * given status code. Repeatable to document multiple status codes.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ResponseFromData
{
    /**
     * @param  class-string  $dataClass
     */
    public function __construct(
        public string $dataClass,
        public int $status = 200,
        public ?string $description = null,
    ) {}
}
