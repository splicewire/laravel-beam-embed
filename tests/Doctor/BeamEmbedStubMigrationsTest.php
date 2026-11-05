<?php

namespace Splicewire\Beam\Embed\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Embed\BeamEmbedServiceProvider;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * beam-embed's own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which package is mine."
 */
class BeamEmbedStubMigrationsTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_embed_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'splicewire/laravel-beam-embed';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return BeamEmbedServiceProvider::class;
    }
}
