<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Contracts\Conversation;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;

/**
 * The default {@see KnowledgePivotFreezer} bound on a bare beam site (recohere T02): a no-op. beam-core's
 * generic ChatBase carries no knowledge relations, so there is nothing to freeze — spawning an embed
 * session still works, it just carries no grounding until a host (tower-embed) binds the real freezer.
 */
class NullKnowledgePivotFreezer implements KnowledgePivotFreezer
{
    public function freeze(Conversation $session, Conversation $template): void
    {
        // No knowledge pivots on a bare beam substrate — intentionally does nothing.
    }
}
