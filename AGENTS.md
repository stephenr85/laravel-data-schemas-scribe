> You are in **rushing/laravel-data-schemas-scribe** — a Scribe bridge that drives request/response extraction from Laravel Data classes via `schemastud/laravel-data-schemas`.

## Particle doctrine

Before adding or changing any I/O surface (HTTP route, MCP tool, Inertia page, command), read
`~/Workspaces/splicewire-beam-runbook/references/particle-doctrine.md` — the
declare-every-boundary-crossing-shape invariant, its three declaration sites, the four exceptions,
and `splicewire:beam:manifests --json` for locating the registry behind a surface.

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
