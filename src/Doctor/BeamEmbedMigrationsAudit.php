<?php

namespace Splicewire\Beam\Embed\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Embed\BeamEmbedServiceProvider;

class BeamEmbedMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-embed';
    }

    protected function serviceProviderClass(): string
    {
        return BeamEmbedServiceProvider::class;
    }
}
