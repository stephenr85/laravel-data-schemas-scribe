<?php

namespace Rushing\LaravelDataSchemasScribe\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * The negative half of the request-axis required rule: a query DTO whose properties carry NO default.
 * `WidgetQueryData` is all-defaulted, so on its own it cannot tell "defaulted properties dropped out of
 * `required`" from "the required rung stopped working at all" — the over-correction that produced the
 * downstream form-layer workaround (api-surface-coherence 71). `$region` is nullable with no default,
 * which is the exact shape that workaround got backwards.
 */
class MandatoryFilterQueryData extends Data
{
    public function __construct(
        #[Description('The tenant whose widgets are listed.')]
        public string $tenant,
        #[Description('Restrict to one region; explicitly null for all regions.')]
        public ?string $region,
    ) {}
}
