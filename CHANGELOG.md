# Changelog

All notable changes to `rushing/laravel-data-schemas-scribe` are documented here.

## Unreleased

### Changed
- `OpenApi\DataSchemaGenerator::pathItem()` now advertises a request body as
  `multipart/form-data` (instead of the hard-coded `application/json`) when the request
  schema carries a `format: binary` leaf — the signal the data-schemas generator emits for
  an `UploadedFile`-typed property. Bodies without a binary leaf are unchanged. Pairs with
  the `schemastud/laravel-data-schemas` binary-format mapping so pure-Data upload endpoints
  keep their `multipart/form-data` + `format: binary` OpenAPI shape.
