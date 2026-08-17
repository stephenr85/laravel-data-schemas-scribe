> You are in **rushing/laravel-data-schemas-scribe** — a Scribe bridge that drives request/response extraction from Laravel Data classes via `schemastud/laravel-data-schemas`.

## The Data class is the only source

Every axis this bridge extracts comes from a declared Data class. There is no fallback path that
reads a shape from an inline array or a runtime sample, and adding one would be a regression: the
whole point is that the schema, the docs, and the generated client types derive from one declaration
and therefore cannot disagree.

Consumers may impose a stricter rule on top — a CMS runtime, for instance, requiring that every
boundary-crossing shape be declared rather than inline, with its own registry and its own attributes
for saying so. This bridge neither knows nor enforces that. It extracts what it is given, from the
Data class it is pointed at.

## Parameter documentation

Prose and examples for a documented parameter are declared **on the Data class** (`#[Description]`,
`#[Example]`), never duplicated into a `@bodyParam` / `@queryParam` docblock. Before writing or changing
a strategy, read `docs/parameter-attributes.md` — in particular which axes `DataSchemaGenerator`
overwrites (`requestBody`, `responses`) and which reach the spec verbatim (`parameters`), because the
example-handling obligation differs between them.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
