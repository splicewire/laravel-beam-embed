<?php

namespace Splicewire\Beam\Embed\Tests\Capability;

use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\TrustTier;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * The tier ceiling is the outermost safety property of the whole brokered-capability surface (RCP-05):
 * an untrusted remote publisher runs arbitrary third-party code, and the ONE thing it must never reach
 * is a server-side write. That rule lives in exactly two tiny methods here, and nothing else in the
 * package re-states it — so an editor widening {@see TrustTier::defaultCapabilities()} "to make the
 * demo work" would silently hand every untrusted publisher a save, with no other test in the estate
 * objecting.
 *
 * These assertions are deliberately written against the CAPABILITY SET, not a count, so adding a new
 * capability to the enum forces a conscious decision about which tier gets it.
 */
class TrustTierTest extends TestCase
{
    public function test_untrusted_publisher_is_read_only_and_can_never_reach_a_save(): void
    {
        $tier = TrustTier::UntrustedPublisher;

        $this->assertSame(
            [Capability::Resolve, Capability::ReadScoped],
            $tier->defaultCapabilities(),
        );

        $this->assertTrue($tier->permits(Capability::Resolve));
        $this->assertTrue($tier->permits(Capability::ReadScoped));
        $this->assertFalse($tier->permits(Capability::RequestSave));
    }

    public function test_first_party_gets_the_wide_grant_including_save(): void
    {
        $tier = TrustTier::FirstParty;

        $this->assertSame(
            [Capability::Resolve, Capability::ReadScoped, Capability::RequestSave],
            $tier->defaultCapabilities(),
        );

        foreach (Capability::cases() as $capability) {
            $this->assertTrue($tier->permits($capability), "first party should permit {$capability->value}");
        }
    }

    public function test_permits_is_exactly_the_default_set_for_every_tier(): void
    {
        // permits() and defaultCapabilities() are the mint-time and broker-time halves of the SAME rule
        // (the token is checked twice, deliberately). If they ever drift, a token could be minted with a
        // capability the broker then refuses — or worse, the reverse.
        foreach (TrustTier::cases() as $tier) {
            foreach (Capability::cases() as $capability) {
                $this->assertSame(
                    in_array($capability, $tier->defaultCapabilities(), true),
                    $tier->permits($capability),
                    "{$tier->value} / {$capability->value}",
                );
            }
        }
    }

    public function test_capability_names_round_trip_and_an_unknown_name_is_null_not_an_error(): void
    {
        // The broker feeds a raw, guest-supplied string through tryFromName(); returning null (rather
        // than throwing) is what lets the broker audit an `unknown_capability` refusal instead of 500ing.
        $this->assertSame(Capability::Resolve, Capability::tryFromName('resolve'));
        $this->assertSame(Capability::ReadScoped, Capability::tryFromName('read_scoped'));
        $this->assertSame(Capability::RequestSave, Capability::tryFromName('request_save'));
        $this->assertNull(Capability::tryFromName('drop_database'));
        $this->assertNull(Capability::tryFromName(null));
    }
}
