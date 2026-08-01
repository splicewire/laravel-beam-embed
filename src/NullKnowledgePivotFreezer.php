<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Models\ChatBase;

/**
 * The default {@see KnowledgePivotFreezer} bound on a bare beam site (recohere T02): a no-op. beam-core's
 * generic ChatBase carries no knowledge relations, so there is nothing to freeze — spawning an embed
 * session still works, it just carries no grounding until a host (tower-embed) binds the real freezer.
 */
class NullKnowledgePivotFreezer implements KnowledgePivotFreezer
{
    public function freeze(ChatBase $session, ChatBase $template): void
    {
        // No knowledge pivots on a bare beam substrate — intentionally does nothing.
    }
}
