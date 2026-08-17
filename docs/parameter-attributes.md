# Documenting parameters from Data classes

What this package gives you, and where each piece lands in the generated OpenAPI. This is a
**capability** brief — it describes what the attributes do, not what you are obliged to use. (A host
that makes one of them mandatory says so in its own docs; see `splicewire/laravel-beam` for one such
policy.)

## The attributes

| attribute | axis | what it drives |
| --- | --- | --- |
| `#[RequestFromData(SomeData::class)]` | request body | `requestBody.content.*.schema` |
| `#[ResponseFromData(SomeData::class, status: 200)]` | response | `responses.<status>.content.*.schema` |
| `#[StreamsFromData(...)]` | SSE | the `x-sse-events` payload union |

Each names a `Spatie\LaravelData\Data` subclass. `RequestFromData` and `ResponseFromData` may be
omitted where the method signature already makes the class unambiguous — `UseDataRequest` falls back to
the first typed `Data` parameter. Prefer the explicit attribute anyway: it survives a signature change.

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

It does **not** own OpenAPI's `parameters` array. Anything documenting URL or query parameters returns
flat parameters that reach the spec **verbatim**, so those strategies own their own type and example
handling.

## Examples: absent by design

`JsonSchemaGenerator::inferExample()` deliberately emits **no** example for a bare-typed leaf. It is not
an oversight — a lone `examples` entry makes RJSF render a phantom `<datalist>` dropdown on what should
be a plain input. Format- and enum-derived examples still come through; everything else is opt-in via
`#[Example]`.

Consequence for anyone writing a strategy in the flat-parameter axes: a null example is Scribe's cue to
generate a faker value. Scribe's `'No-example'` sentinel suppresses that — but it is normalized in
`GetParamsFromAttributeStrategy::normalizeParameterData()`, and `GetParamsFromAttributeStrategy extends
PhpAttributeStrategy`, **not the reverse**. A strategy extending `PhpAttributeStrategy` directly (as the
`UseData*` family does) does not inherit that handling and must do it itself, or the literal string
`No-example` ships as the example.
