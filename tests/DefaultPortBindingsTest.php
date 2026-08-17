<?php

namespace Splicewire\Beam\Embed\Tests;

use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Embed\DefaultCapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\Thread;
use Splicewire\Beam\Embed\NullKnowledgePivotFreezer;
use Splicewire\Beam\Embed\NullScopeResolver;
use Splicewire\Beam\Embed\NullSubmissionRecorder;

/**
 * beam-embed's whole topology claim is that a BARE beam site — no tower, no corpus — boots the full
 * remote-embed substrate with safe no-op defaults, and a host overrides them. That claim is four
 * `bind()` lines in the provider, and nothing else in the package asserts them.
 *
 * The defect this catches is a silent one: drop or typo a binding and the package still installs fine,
 * because every consumer in the estate (tower-embed) rebinds all four anyway. The breakage only appears
 * on a bare beam site — where `CapabilityGrant::forSession()`'s `app(ScopeResolver::class)` would throw
 * a BindingResolutionException at the moment a visitor tries to mint a token.
 */
class DefaultPortBindingsTest extends TestCase
{
    public function test_a_bare_beam_site_resolves_every_inversion_port_to_its_no_op_default(): void
    {
        $this->assertInstanceOf(NullKnowledgePivotFreezer::class, $this->app->make(KnowledgePivotFreezer::class));
        $this->assertInstanceOf(NullScopeResolver::class, $this->app->make(ScopeResolver::class));
        $this->assertInstanceOf(NullSubmissionRecorder::class, $this->app->make(SubmissionRecorder::class));
        $this->assertInstanceOf(DefaultCapabilityAuditRecorder::class, $this->app->make(CapabilityAuditRecorder::class));
    }

    public function test_the_default_scope_resolver_answers_an_empty_scope_in_the_documented_shape(): void
    {
        // Shape, not just emptiness: CapabilityGrant::forSession() reads `siloIds`/`tagIds` off this
        // array. A resolver returning `[]` (or snake_case keys) would produce a grant with no scope AND
        // no error — which reads as "deny by default" until a host binds a resolver whose keys differ,
        // at which point the mismatch silently drops the real scope instead.
        $scope = (new NullScopeResolver)->resolve(new Thread);

        $this->assertSame(['siloIds' => [], 'tagIds' => []], $scope);
    }
}
