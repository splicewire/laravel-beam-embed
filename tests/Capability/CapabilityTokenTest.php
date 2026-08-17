<?php

namespace Splicewire\Beam\Embed\Tests\Capability;

use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Capability\CapabilityToken;
use Splicewire\Beam\Embed\Capability\TrustTier;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * {@see CapabilityToken} is a hand-rolled JWT: the estate deliberately did not take a JWT library
 * (the docblock records why — Sanctum abilities need a tokenable model the embed path does not have),
 * which means the signature check, the base64url encoding, and the audience restriction are OUR code
 * and nobody else's test suite covers them.
 *
 * Every case below is a forgery a guest could actually attempt from inside the isolated VM, because the
 * guest holds the token in plaintext: flip a claim byte, replay a neighbour's token, strip the
 * signature. `verify()` returning a non-null token for any of these is a full authorization bypass —
 * the grant it reconstructs is what the broker then trusts.
 */
class CapabilityTokenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // The signing key. Set explicitly so a testbench default change can never turn a signature
        // assertion into a vacuous one.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    private function grant(
        TrustTier $tier = TrustTier::UntrustedPublisher,
        string $audience = 'pk_alpha',
    ): CapabilityGrant {
        return new CapabilityGrant(
            tier: $tier,
            capabilities: [Capability::Resolve, Capability::ReadScoped],
            embedKeyId: $audience,
            sessionId: 'session-1',
            siloIds: ['silo-a'],
            tagIds: ['tag-a'],
        );
    }

    private function token(?CapabilityGrant $grant = null, int $ttl = 300): CapabilityToken
    {
        $now = time();

        return new CapabilityToken($grant ?? $this->grant(), $now, $now + $ttl);
    }

    public function test_a_minted_token_round_trips_its_grant_intact(): void
    {
        $jwt = $this->token()->toJwt();

        $verified = CapabilityToken::verify($jwt, 'pk_alpha');

        $this->assertNotNull($verified);
        $this->assertSame(TrustTier::UntrustedPublisher, $verified->grant->tier);
        $this->assertSame([Capability::Resolve, Capability::ReadScoped], $verified->grant->capabilities);
        $this->assertSame('pk_alpha', $verified->grant->embedKeyId);
        $this->assertSame('session-1', $verified->grant->sessionId);
        $this->assertSame(['silo-a'], $verified->grant->siloIds);
        $this->assertSame(['tag-a'], $verified->grant->tagIds);
    }

    public function test_the_wire_form_is_three_base64url_segments_with_no_padding(): void
    {
        // base64url, not base64: a `+` or `/` in the token would be mangled by the URL/header transports
        // the guest injects it through, and `=` padding is stripped by convention. A regression to plain
        // base64_encode() would work locally and fail intermittently in transit.
        $jwt = $this->token()->toJwt();

        $this->assertMatchesRegularExpression('#^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$#', $jwt);
    }

    public function test_a_tampered_payload_is_refused(): void
    {
        // The forgery this whole mechanism exists to stop: rewrite the claims to name the wide tier and
        // the save capability, keeping the original signature.
        $jwt = $this->token()->toJwt();
        [$header, , $signature] = explode('.', $jwt);

        $forged = rtrim(strtr(base64_encode(json_encode([
            'tier' => 'first_party',
            'caps' => ['resolve', 'read_scoped', 'request_save'],
            'scope' => ['silos' => [], 'tags' => []],
            'sub' => 'session-1',
            'aud' => 'pk_alpha',
            'iat' => time(),
            'exp' => time() + 300,
        ])), '+/', '-_'), '=');

        $this->assertNull(CapabilityToken::verify("{$header}.{$forged}.{$signature}", 'pk_alpha'));
    }

    public function test_a_token_minted_for_another_embed_is_refused(): void
    {
        // Replay across embeds: `pk_` values are public, so a guest can trivially obtain a token for one
        // embed and present it on another embed's request.
        $jwt = $this->token($this->grant(audience: 'pk_alpha'))->toJwt();

        $this->assertNotNull(CapabilityToken::verify($jwt, 'pk_alpha'));
        $this->assertNull(CapabilityToken::verify($jwt, 'pk_beta'));
    }

    public function test_a_token_signed_with_a_different_app_key_is_refused(): void
    {
        $jwt = $this->token()->toJwt();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));

        $this->assertNull(CapabilityToken::verify($jwt, 'pk_alpha'));
    }

    public function test_structurally_malformed_tokens_are_refused_rather_than_throwing(): void
    {
        // The guest controls this string entirely. Any of these throwing instead of returning null
        // would turn a refusal into an unhandled 500 on a public endpoint.
        foreach (['', 'not-a-token', 'a.b', 'a.b.c.d', 'a.b.c'] as $malformed) {
            $this->assertNull(CapabilityToken::verify($malformed, 'pk_alpha'), $malformed);
        }
    }

    public function test_a_correctly_signed_token_with_an_unknown_tier_is_refused(): void
    {
        // Not reachable by forgery (it needs the key) but very reachable by a future rename of a
        // TrustTier case: an in-flight token naming a retired tier must refuse, never default to a tier.
        $jwt = $this->signedJwt([
            'tier' => 'super_admin',
            'caps' => ['resolve'],
            'aud' => 'pk_alpha',
            'sub' => 'session-1',
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $this->assertNull(CapabilityToken::verify($jwt, 'pk_alpha'));
    }

    public function test_unknown_capability_tokens_in_the_claims_are_dropped_not_carried(): void
    {
        // A capability retired from the enum must vanish from the reconstructed grant rather than
        // surviving as a null entry that in_array() could match loosely.
        $jwt = $this->signedJwt([
            'tier' => 'untrusted_publisher',
            'caps' => ['resolve', 'exfiltrate_everything'],
            'aud' => 'pk_alpha',
            'sub' => 'session-1',
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $verified = CapabilityToken::verify($jwt, 'pk_alpha');

        $this->assertNotNull($verified);
        $this->assertSame([Capability::Resolve], $verified->grant->capabilities);
    }

    public function test_expiry_is_reported_off_the_exp_claim(): void
    {
        $this->assertFalse($this->token(ttl: 300)->isExpired());
        $this->assertTrue($this->token(ttl: -1)->isExpired());

        // And it survives the wire: an expired token still VERIFIES (the signature is good) — it is the
        // broker that must reject it on isExpired(). Collapsing the two would lose the audit distinction
        // between an expired token and a forged one.
        $jwt = $this->token(ttl: -1)->toJwt();
        $verified = CapabilityToken::verify($jwt, 'pk_alpha');

        $this->assertNotNull($verified);
        $this->assertTrue($verified->isExpired());
    }

    public function test_verify_without_an_expected_audience_still_enforces_the_signature(): void
    {
        $jwt = $this->token()->toJwt();

        $this->assertNotNull(CapabilityToken::verify($jwt));
        $this->assertNull(CapabilityToken::verify($jwt.'x'));
    }

    /**
     * Sign an arbitrary claims array with the configured app key — the mint's private half, reproduced
     * so a test can build claims the public API would never emit.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signedJwt(array $claims): string
    {
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'SWC-CAP']));
        $payload = $b64(json_encode($claims));
        $signature = $b64(hash_hmac('sha256', "{$header}.{$payload}", $key, true));

        return "{$header}.{$payload}.{$signature}";
    }
}
