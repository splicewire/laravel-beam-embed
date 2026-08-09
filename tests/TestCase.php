<?php

namespace Splicewire\Beam\Embed\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Embed\BeamEmbedServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BeamEmbedServiceProvider::class,
        ];
    }
}
