<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\LaravelDataSchemasScribe\LaravelDataSchemasScribeServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * The strategies reflect Data classes and need nothing booted, but any test that exercises
     * Spatie at RUNTIME does: `Data::from()` reads `config('data')` through
     * `CreationContextFactory::createFromConfig()` and fatals without the package's own provider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,

            // Same omission as the line above, one dependency further down, and it does not fail —
            // it answers. Every strategy in `src/Strategies` builds its generator as
            // `new JsonSchemaGenerator((array) config('data-schemas', []))`, and testbench does not
            // auto-discover, so without this provider that config file is never merged: the `??`
            // default hands the generator an EMPTY array while the package's own
            // `config/data-schemas.php` sits right there holding `schema_version`,
            // `schema_metadata`, `strategies` and `base_uri`. The strategies still run and still
            // return a schema — one with no `$schema` and no `$id`, asserted green here and wrong
            // at any host. Measured before the fix: `config('data-schemas')` was `[]` and the
            // response document for `Tests\Fixtures\WidgetData` came back as
            // `[type, title, properties, required, $defs]`; with the provider it is
            // `[$schema, type, title, properties, required, $id, $defs]`, and the 18 tests here pass
            // either way because none of them asserts on identity. It is the `SchemaIdResolver` trap
            // recorded in `splicewire/tower`'s TestCase, reached through a config read instead of a
            // container binding.
            LaravelDataSchemasServiceProvider::class,

            LaravelDataSchemasScribeServiceProvider::class,
        ];
    }
}
