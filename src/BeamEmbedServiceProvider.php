<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;

/**
 * The generic embed subsystem provider (recohere T02). Binds the NO-OP defaults for the two
 * knowledge-grounding inversion ports so a bare beam site can spawn embed sessions and mint capability
 * grants out of the box — with no grounding, which is the correct posture for a substrate that has no
 * knowledge relations. A host (tower-embed's TowerEmbedServiceProvider) OVERRIDES both bindings with
 * the real tower-Thread-backed implementations.
 */
class BeamEmbedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KnowledgePivotFreezer::class, NullKnowledgePivotFreezer::class);
        $this->app->bind(ScopeResolver::class, NullScopeResolver::class);
        $this->app->bind(SubmissionRecorder::class, NullSubmissionRecorder::class);
    }

    public function boot(): void
    {
        // No boot-time work: routes/controllers/config stay with the host (tower-embed).
    }
}
