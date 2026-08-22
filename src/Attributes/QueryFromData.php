<?php

namespace Rushing\LaravelDataSchemasScribe\Attributes;

use Attribute;

/**
 * Declare the Spatie Data class that describes an endpoint's QUERY STRING — the query-axis sibling of
 * {@see RequestFromData}.
 *
 *     #[QueryFromData(WidgetQueryData::class)]
 *     public function index() {}
 *
 * ## This DOCUMENTS the contract; it does not BIND it
 *
 * Stated here so no consumer assumes otherwise. The strategy behind this attribute **reflects** the
 * named class — it never hydrates it — so pointing at a class is a claim about the wire, not a
 * guarantee the wire is read through it.
 *
 * Spatie *can* hydrate a `Data` object from a GET query string: `Data::from($request)` and the
 * controller-parameter injection both read `$request->all()`, which merges the query string for every
 * verb, and the package's casts turn the resulting strings into typed properties. So a host is free to
 * make the same class the actual input contract. But nothing in this package checks that it did — a
 * route may reflect one class and read its query with `$request->query()` by hand, and the spec will
 * not notice. If the DTO is also your runtime contract, that is your host's arrangement to keep true.
 *
 * ## Not repeatable, on purpose
 *
 * A query string is one flat namespace, so merging several DTOs into it is superficially plausible —
 * and the `app/Scribe/` lineage this package extracts did mark its version `IS_REPEATABLE`. In ten
 * live call sites across that estate it was never once repeated. An unexercised merge rule is a
 * second way to spell one declaration, so this mirrors {@see RequestFromData}: one class, one axis. A
 * route that genuinely needs two shapes composes them into one Data class, where the schema can see
 * the composition.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class QueryFromData
{
    /**
     * @param  class-string  $dataClass
     */
    public function __construct(
        public string $dataClass,
        public ?string $description = null,
    ) {}
}
