<?php

namespace Splicewire\Beam\Embed\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Splicewire\Beam\Embed\BeamEmbedServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // laravel-popcorn binds RegistryIndex as a SINGLETON, and testbench does NOT auto-discover
            // it — requiring the package is not enough. Without this the index is auto-resolvable but
            // UNSHARED, so BeamEmbedServiceProvider::packageBooted()'s describe() lands on a throwaway,
            // every index assertion below silently tests an empty index, and the suite stays green over
            // a registry nothing can route to (registry-kernel 27 D3).
            PopcornServiceProvider::class,
            BeamEmbedServiceProvider::class,
        ];
    }
}
