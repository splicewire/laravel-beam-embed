<?php

namespace Splicewire\Beam\Embed\Tests;

use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Embed\DefaultCapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\Thread;
use Splicewire\Beam\Embed\NullKnowledgePivotFreezer;
use Splicewire\Beam\Embed\NullScopeResolver;

/**
 * beam-embed's whole topology claim is that a BARE beam site — no tower, no corpus — boots the full
 * remote-embed substrate with safe no-op defaults, and a host overrides them. That claim is three
 * `bind()` lines in the provider, and nothing else in the package asserts them.
 *
 * The defect this catches is a silent one: drop or typo a binding and the package still installs fine,
 * because every consumer in the estate (tower-embed) rebinds them anyway. The breakage only appears
 * on a bare beam site — where `CapabilityGrant::forSession()`'s `app(ScopeResolver::class)` would throw
 * a BindingResolutionException at the moment a visitor tries to mint a token.
 *
 * It was FOUR ports until beam-facade ticket 63 deleted `SubmissionRecorder`. The distinction that
 * singled that one out, recorded here so this file is not read as the opening move against the rest:
 * the defaults below are honest absences (an empty method body, an empty scope) or the correct
 * baseline write, whereas that port's default performed a DIFFERENT write from the one every host
 * rebound it to — the only one whose `Null` prefix lied about what it did.
 */
class DefaultPortBindingsTest extends TestCase
{
    public function test_a_bare_beam_site_resolves_every_inversion_port_to_its_no_op_default(): void
    {
        $this->assertInstanceOf(NullKnowledgePivotFreezer::class, $this->app->make(KnowledgePivotFreezer::class));
        $this->assertInstanceOf(NullScopeResolver::class, $this->app->make(ScopeResolver::class));
        $this->assertInstanceOf(DefaultCapabilityAuditRecorder::class, $this->app->make(CapabilityAuditRecorder::class));
    }

    /**
     * The deletion as a gate that re-runs, not a one-time grep (beam-facade ticket 32's precedent).
     * Re-introducing either class would restore a straight-to-model escalation write that emits no
     * `BeamParticlePersisted` — silently, since nothing else in the package would fail.
     */
    public function test_the_submission_recorder_port_and_its_straight_to_model_default_stay_deleted(): void
    {
        $this->assertFalse(interface_exists('Splicewire\Beam\Embed\Contracts\SubmissionRecorder'));
        $this->assertFalse(class_exists('Splicewire\Beam\Embed\NullSubmissionRecorder'));
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
