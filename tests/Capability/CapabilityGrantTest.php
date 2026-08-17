<?php

namespace Splicewire\Beam\Embed\Tests\Capability;

use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Capability\CapabilityToken;
use Splicewire\Beam\Embed\Capability\TrustTier;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * {@see CapabilityGrant::permits()} is the defense-in-depth conjunction: a capability must be in the
 * grant's own list AND permitted by the grant's tier. The AND is the whole point — a token's `caps`
 * claim is attacker-adjacent data (it is decoded from the wire in
 * {@see CapabilityToken::verify()}), so a grant reconstructed from a
 * forged-but-correctly-signed-looking payload must not be able to smuggle in a capability its tier
 * never allowed.
 *
 * A refactor that "simplifies" this to a single `in_array($capability, $this->capabilities)` would pass
 * every happy-path test in the estate and quietly delete the second check. That is the defect this
 * fixture exists to catch.
 */
class CapabilityGrantTest extends TestCase
{
    public function test_a_grant_cannot_smuggle_a_capability_its_tier_forbids(): void
    {
        // The shape a forged/hand-built claims payload would produce: an untrusted publisher whose
        // `caps` list nonetheless names the save capability.
        $grant = new CapabilityGrant(
            tier: TrustTier::UntrustedPublisher,
            capabilities: [Capability::Resolve, Capability::RequestSave],
            embedKeyId: 'pk_test',
            sessionId: 'session-1',
        );

        $this->assertTrue($grant->permits(Capability::Resolve));
        $this->assertFalse($grant->permits(Capability::RequestSave));
    }

    public function test_a_capability_the_tier_allows_but_the_grant_omits_is_still_refused(): void
    {
        // The narrowing half: a first-party mint that requested only `resolve` must not silently widen
        // back to the tier's full default set.
        $grant = new CapabilityGrant(
            tier: TrustTier::FirstParty,
            capabilities: [Capability::Resolve],
            embedKeyId: 'pk_test',
            sessionId: 'session-1',
        );

        $this->assertTrue($grant->permits(Capability::Resolve));
        $this->assertFalse($grant->permits(Capability::ReadScoped));
        $this->assertFalse($grant->permits(Capability::RequestSave));
    }

    public function test_claims_carry_the_tier_capability_tokens_and_the_frozen_scope(): void
    {
        // toClaims() is the wire contract verify() reads back. A renamed key here breaks every existing
        // in-flight token silently: verify() would decode a null tier and refuse EVERY call.
        $grant = new CapabilityGrant(
            tier: TrustTier::FirstParty,
            capabilities: [Capability::Resolve, Capability::ReadScoped],
            embedKeyId: 'pk_test',
            sessionId: 'session-1',
            siloIds: ['silo-a', 'silo-b'],
            tagIds: ['tag-a'],
        );

        $this->assertSame([
            'tier' => 'first_party',
            'caps' => ['resolve', 'read_scoped'],
            'scope' => ['silos' => ['silo-a', 'silo-b'], 'tags' => ['tag-a']],
        ], $grant->toClaims());
    }

    public function test_an_empty_grant_permits_nothing(): void
    {
        $grant = new CapabilityGrant(
            tier: TrustTier::FirstParty,
            capabilities: [],
            embedKeyId: 'pk_test',
            sessionId: 'session-1',
        );

        foreach (Capability::cases() as $capability) {
            $this->assertFalse($grant->permits($capability));
        }
    }
}
