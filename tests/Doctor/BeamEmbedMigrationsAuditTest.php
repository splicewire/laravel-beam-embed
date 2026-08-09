<?php

namespace Splicewire\Beam\Embed\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Embed\Doctor\BeamEmbedMigrationsAudit;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * beam-embed's own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamEmbedMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_embed_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamEmbedMigrationsAudit::class;
    }
}
