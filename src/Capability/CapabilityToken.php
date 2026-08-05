<?php

namespace Splicewire\Beam\Embed\Capability;

use Illuminate\Support\Facades\Config;
use Splicewire\Beam\Contracts\Conversation;

/**
 * The scoped capability token (RCP-05): a short-lived, audience-restricted credential
 * minted HOST-SIDE and injected into the isolated guest's separate-origin VM. Holding
 * only this, the guest can invoke the narrow brokered-capability surface — and nothing
 * else. The trust boundary is the execution context, not a guarded API: the token is
 * real only off-origin, and every capability it authorizes still runs on the host.
 *
 * MECHANISM CHOICE — a signed short-TTL token (JWT-shaped: base64url
 * header.payload.signature, HS256 over the app key), NOT Sanctum abilities. Sanctum
 * abilities ride a PersonalAccessToken bound to a TOKENABLE MODEL; the embed path has
 * no User — a Visitor owns the session — so Sanctum would add a parallel token store
 * and a guard the embed path doesn't use. A signed claims token binds DIRECTLY to the
 * existing embed session: `sub` = session id, `aud` = embed_key id, scope claims read
 * off the session's frozen `snapshot_config`. It reuses the app key (existing infra),
 * adds no table, and re-mints no session — exactly the "reuse, don't re-mint" rule.
 *
 * The signature is verified before ANY claim is trusted, so the guest cannot forge a
 * wider grant, a different audience, or a later expiry.
 */
class CapabilityToken
{
    public function __construct(
        public CapabilityGrant $grant,
        public int $issuedAt,
        public int $expiresAt,
    ) {}

    /**
     * Mint a signed token for a spawned session under a tier. TTL is short (default 300s)
     * — a remote block renders once; a long-lived capability credential is a liability.
     */
    public static function mint(Conversation $session, TrustTier $tier, string $embedKeyId, ?array $requested = null, ?int $ttlSeconds = null): self
    {
        $grant = CapabilityGrant::forSession($session, $tier, $embedKeyId, $requested);
        $now = time();
        $ttl = $ttlSeconds ?? (int) Config::get('embed.capability.ttl_seconds', 300);

        return new self($grant, $now, $now + $ttl);
    }

    /** The compact, signed wire form injected into the guest VM. */
    public function toJwt(): string
    {
        $header = static::b64(json_encode(['alg' => 'HS256', 'typ' => 'SWC-CAP']));

        $payload = static::b64(json_encode(array_merge($this->grant->toClaims(), [
            'sub' => $this->grant->sessionId,
            'aud' => $this->grant->embedKeyId,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
        ])));

        $signature = static::b64(static::sign("{$header}.{$payload}"));

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify a token's signature, decode its claims, and reconstruct the grant. Returns
     * null on ANY failure — bad signature, malformed, or (when $expectedAudience is
     * given) a cross-audience token minted for a different embed. Expiry is enforced by
     * {@see isExpired()} which the broker checks after decode.
     */
    public static function verify(string $jwt, ?string $expectedAudience = null): ?self
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $expected = static::b64(static::sign("{$header}.{$payload}"));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode(static::unb64($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        // Audience restriction: a token minted for embed A is refused for embed B.
        if ($expectedAudience !== null && ($claims['aud'] ?? null) !== $expectedAudience) {
            return null;
        }

        $tier = TrustTier::tryFrom((string) ($claims['tier'] ?? ''));
        if ($tier === null) {
            return null;
        }

        $caps = array_values(array_filter(array_map(
            fn ($c) => Capability::tryFrom((string) $c),
            (array) ($claims['caps'] ?? []),
        )));

        $grant = new CapabilityGrant(
            tier: $tier,
            capabilities: $caps,
            embedKeyId: (string) ($claims['aud'] ?? ''),
            sessionId: (string) ($claims['sub'] ?? ''),
            siloIds: array_map('strval', (array) data_get($claims, 'scope.silos', [])),
            tagIds: array_map('strval', (array) data_get($claims, 'scope.tags', [])),
        );

        return new self($grant, (int) ($claims['iat'] ?? 0), (int) ($claims['exp'] ?? 0));
    }

    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    protected static function sign(string $signingInput): string
    {
        return hash_hmac('sha256', $signingInput, static::key(), true);
    }

    protected static function key(): string
    {
        $key = (string) Config::get('app.key');

        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7));
        }

        return $key;
    }

    protected static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected static function unb64(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'));
    }
}
