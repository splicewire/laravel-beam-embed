<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Contracts\ScopeResolver;
use Splicewire\Beam\Models\ChatBase;

/**
 * The default {@see ScopeResolver} bound on a bare beam site (recohere T02): an empty scope. A bare
 * beam substrate has no ContextScope grounding, so a capability grant resolves to no silos/tags — and
 * the broker's deny-by-default posture (empty scope reads nothing) holds. A host (tower-embed) binds
 * the real resolver to reach the tower Thread's ContextScopes.
 */
class NullScopeResolver implements ScopeResolver
{
    /**
     * @return array{siloIds: list<string>, tagIds: list<string>}
     */
    public function resolve(ChatBase $session): array
    {
        return ['siloIds' => [], 'tagIds' => []];
    }
}
