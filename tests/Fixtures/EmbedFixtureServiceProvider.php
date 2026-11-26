<?php

namespace Splicewire\Beam\Embed\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;

class EmbedFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(EmbedTypeRegistry::class, function (EmbedTypeRegistry $registry): void {
            $registry->register(new FixtureEmbedType);
        });
    }
}
