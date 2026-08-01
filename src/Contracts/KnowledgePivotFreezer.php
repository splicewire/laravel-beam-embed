<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Models\ChatBase;

/**
 * The knowledge-grounding INVERSION port (recohere T02). When {@see \Splicewire\Beam\Embed\EmbedSpawner}
 * spawns an embed session from a template, the template's knowledge pivots (fragments / silos /
 * context-scopes) must be FROZEN onto the session so its config is provenance-stable. beam-core's
 * generic {@see ChatBase} carries NO knowledge relations — those live UP on the tower Thread — so the
 * spawner cannot copy them directly. It delegates to this port instead:
 *
 *  - a bare beam site binds the {@see \Splicewire\Beam\Embed\NullKnowledgePivotFreezer} (a no-op —
 *    there are no knowledge pivots to freeze), so spawning works out of the box;
 *  - tower-embed binds `TowerPivotFreezer`, which re-resolves template + session as tower Threads by
 *    id and does the real fragments/silos/contextScopes attach/sync.
 *
 * This is the T02 fork resolution: the knowledge relation stays UP on tower, the spawner stays DOWN
 * in beam-embed, and the arrow between them is inverted through this seam.
 */
interface KnowledgePivotFreezer
{
    /**
     * Freeze the template's knowledge pivots onto the freshly-spawned session.
     */
    public function freeze(ChatBase $session, ChatBase $template): void;
}
