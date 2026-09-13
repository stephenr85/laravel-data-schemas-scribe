# Documenting parameters from Data classes

What this package gives you, and where each piece lands in the generated OpenAPI. This is a
**capability** brief — it describes what the attributes do, not what you are obliged to use. (A host
that makes one of them mandatory says so in its own docs; see `splicewire/laravel-beam` for one such
policy.)

## The attributes

| attribute | axis | what it drives |
| --- | --- | --- |
| `#[RequestFromData(SomeData::class)]` | request body | `requestBody.content.*.schema` |
| `#[QueryFromData(SomeData::class)]` | query string | the `in: query` entries of `parameters` |
| `#[ResponseFromData(SomeData::class, status: 200)]` | response | `responses.<status>.content.*.schema` |
| `#[StreamsFromData(...)]` | SSE | the `x-sse-events` payload union |

Each names a `Spatie\LaravelData\Data` subclass. `RequestFromData` and `ResponseFromData` may be
omitted where the method signature already makes the class unambiguous — `UseDataRequest` falls back to
the first typed `Data` parameter. Prefer the explicit attribute anyway: it survives a signature change.

`QueryFromData` has **no** such fallback, on purpose: a route may carry a query DTO and a body DTO at
once, and "the first typed `Data` parameter" would then document one axis as the other. It is also not
repeatable — a route needing two shapes composes them into one class, where the schema can see the
composition.

These attributes **document** a contract; they do not bind it. The strategies reflect the named class
and never hydrate it. Spatie *will* hydrate the same class from a GET query string — both
`Data::from($request)` and handler injection read `$request->all()`, which merges the query string, and
the casts turn the strings into typed properties (both paths are asserted in `QueryBridgeTest`) — so a
host is free to make the documented class the real input contract. Nothing here checks that it did.

## Describing the properties

Prose and examples are declared **on the Data class**, beside the type, using
`schemastud/laravel-data-schemas`:

```php
#[Description('Payload for creating or updating a fragment.')]
class FragmentSaveInputData extends Data
{
    public function __construct(
        #[Description('Satellite-owned external reference, unique within your tenant.')]
        #[Example('numero:archetype:7')]
        public ?string $externalRef = null,
    ) {}
}
```

`JsonSchemaGenerator` reads both attributes — property-level and class-level — and the schema it
produces is what reaches the spec. One declaration site feeds description *and* example; there is no
second place to keep in sync.

## What reaches the OpenAPI artifact, and what doesn't

This distinction is load-bearing and easy to get wrong.

`DataSchemaGenerator::pathItem()` **overwrites** the `requestBody` and `responses` schema nodes with the
generator's full `$ref`-based schema, and config generators run *after* Scribe's core ones. So for those
two axes:

- The rich schema wins. `$defs` are hoisted into `components/schemas` and refs rewritten.
- `ScribeBodyParameters::fromSchema()`'s flat output — the `name`/`type`/`example` shape — reaches only
  Scribe's HTML and Postman surfaces, **not** the OpenAPI file. If a host doesn't ship those surfaces,
  it is dead weight there.

URL and query strategies still own their flat parameters' types, descriptions and examples. The
bridge also restores schema information that Scribe's flat parameter model or base writer drops:

- `pathParameters()` preserves non-empty `enumValues` as a path parameter's `schema.enum`.
- A path strategy can stash complete schemas by wire parameter name in
  `custom['dataPathParameterSchemas']`. `pathItem()` writes these as operation-level path parameters,
  preserving constraints that differ between operations sharing a path. An empty vocabulary uses
  `not: {}` rather than omitting the constraint and accepting any value.
- `UseDataQuery` stashes the source schema in `custom['dataQuerySchema']`.
  `applyQuerySchemaConstraints()` restores each query property's `default` and `not` keywords onto
  the matching query parameter schema. It leaves headers and path parameters alone.

These restorations do not infer parameter types or examples. A complete path schema replaces the
schema for that operation's parameter, while its description and optional example come from the
extracted parameter. Query parameters retain the flat strategy's type and enum projection.

That asymmetry is why `UseDataQuery` passes `dereference: true` to `ScribeBodyParameters::fromSchema()`
and `UseDataRequest` does not. The generator hoists a backed enum into `$defs` and leaves the property
as a bare `{$ref: …}` carrying no `type` of its own. On the body axis that is fine — the rich schema
overwrites the flat output. On the query axis that flat type and enum projection reaches the published
contract, so an undereferenced enum would ship as `type: object` with its value set dropped. Folding
the def back in recovers the scalar type and the `enum` values.

## Examples: absent by design

`JsonSchemaGenerator::inferExample()` deliberately emits **no** example for a bare-typed leaf. It is not
an oversight — a lone `examples` entry makes RJSF render a phantom `<datalist>` dropdown on what should
be a plain input. Format- and enum-derived examples still come through; everything else is opt-in via
`#[Example]`.

Consequence for anyone writing a strategy in the flat-parameter axes — **and an earlier draft of this
section got it backwards, so it is stated here as measured rather than as remembered.**

Returning `example => null` does **not** trigger faker. Faker fill is per-strategy, not generic:
`Extractor` never synthesizes an example for a strategy's return, and every `generateDummyValue()` call
site in Scribe sits inside a *specific* strategy (the tag strategies, `GetParamsFromAttributeStrategy`,
the inline-validator path, `GetFromLaravelAPI` for URL params). What `null` actually buys is
`Extractor::cleanParams()` dropping the parameter from the rendered *example request* while keeping it
documented — the parameter stays in `parameters` with `example: null`.

So the `'No-example'` sentinel is not the remedy here; it is the hazard. It is normalized in
`GetParamsFromAttributeStrategy::normalizeParameterData()`, and `GetParamsFromAttributeStrategy extends
PhpAttributeStrategy`, **not the reverse** — so a strategy extending `PhpAttributeStrategy` directly (as
the `UseData*` family does) does not inherit that handling, and returning the literal string ships
`example: No-example` into the spec, which is worse than faker. `ScribeBodyParameters::fromSchema()`
never emits it: absent `examples` becomes `null`, which is exactly what this axis wants.
