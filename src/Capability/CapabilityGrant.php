<?php

namespace Splicewire\Beam\Embed\Capability;

use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Models\ChatBase;

/**
 * The explicit capability grant a scoped token carries (RCP-05): which brokered capabilities the guest
 * may invoke, the trust tier they run under, and the scope the grant is bound to — the session's FROZEN
 * silo/tag union, never a parallel scope store and never the request. Deriving the scope from the
 * existing embed session is the whole point: the token REUSES the drop-in embed plumbing, it does not
 * re-mint a session.
 *
 * Recohere T02: the scope is no longer read off a tower ContextScope relation directly (that relation is
 * UP on the tower Thread, absent from the generic {@see ChatBase}). It is resolved through the injected
 * {@see ScopeResolver} port — empty on a bare beam site, the real ContextScope union when tower-embed
 * binds its resolver.
 */
class CapabilityGrant
{
    /**
     * @param  list<Capability>  $capabilities
     * @param  list<string>  $siloIds
     * @param  list<string>  $tagIds
     */
    public function __construct(
        public TrustTier $tier,
        public array $capabilities,
        public string $embedKeyId,
        public string $sessionId,
        public array $siloIds = [],
        public array $tagIds = [],
    ) {}

    /**
     * Build the grant for a spawned embed session under a tier. The mint narrows the tier's default
     * capabilities to those actually requested (never widening past the tier), and reads the scope off
     * the session through the {@see ScopeResolver} port.
     *
     * @param  list<Capability>|null  $requested  narrow to these (still tier-bounded); null = the tier's full default set
     */
    public static function forSession(ChatBase $session, TrustTier $tier, string $embedKeyId, ?array $requested = null): self
    {
        $allowed = $tier->defaultCapabilities();

        if ($requested !== null) {
            $allowed = array_values(array_filter($allowed, fn (Capability $c) => in_array($c, $requested, true)));
        }

        $scope = app(ScopeResolver::class)->resolve($session);

        return new self(
            tier: $tier,
            capabilities: $allowed,
            embedKeyId: $embedKeyId,
            sessionId: (string) $session->id,
            siloIds: array_map('strval', $scope['siloIds'] ?? []),
            tagIds: array_map('strval', $scope['tagIds'] ?? []),
        );
    }

    public function permits(Capability $capability): bool
    {
        // Defense in depth: the capability must be BOTH in this grant AND permitted by
        // the tier. A grant can never smuggle a capability its tier forbids.
        return in_array($capability, $this->capabilities, true) && $this->tier->permits($capability);
    }

    /**
     * @return array<string, mixed> the grant claims embedded in the token
     */
    public function toClaims(): array
    {
        return [
            'tier' => $this->tier->value,
            'caps' => array_map(fn (Capability $c) => $c->value, $this->capabilities),
            'scope' => ['silos' => $this->siloIds, 'tags' => $this->tagIds],
        ];
    }
}
