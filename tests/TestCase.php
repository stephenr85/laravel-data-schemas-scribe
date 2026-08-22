<?php

namespace Rushing\LaravelDataSchemasScribe\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\LaravelDataSchemasScribe\LaravelDataSchemasScribeServiceProvider;
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
            LaravelDataSchemasScribeServiceProvider::class,
        ];
    }
}
