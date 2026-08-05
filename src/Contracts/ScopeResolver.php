<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Contracts\Conversation;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\NullScopeResolver;

/**
 * The capability-scope INVERSION port (recohere T02), the read-side twin of {@see KnowledgePivotFreezer}.
 * A minted capability grant ({@see CapabilityGrant}) is bounded by the
 * frozen silo/tag scope of the session it is minted for — the same non-exclude ContextScope union the
 * Concierge locks retrieval to. beam-core's generic conversation particle has no ContextScope relation (it
 * lives UP on the tower Thread), so the grant resolves the scope through this port instead:
 *
 *  - a bare beam site binds the {@see NullScopeResolver} (empty scope — no
 *    grounding, so a brokered read is deny-by-default, exactly the intended posture);
 *  - tower-embed binds `TowerScopeResolver`, which reads the session's ContextScopes and unions their
 *    silos + tags.
 */
interface ScopeResolver
{
    /**
     * Resolve the frozen silo/tag scope a capability grant is bounded to.
     *
     * @return array{siloIds: list<string>, tagIds: list<string>}
     */
    public function resolve(Conversation $session): array;
}
