<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Support\Collection;

/**
 * The "I don't actually know" signal (DIE-09), reused off the same retrieval /
 * knowledge-engine sourced-fact surface the rest of the platform grounds on: when a
 * scoped query surfaces no grounding, the answer would be a confident guess — so the
 * Concierge should offer the human exit instead. This reads the *signal* (grounding
 * present or not), not any node identity.
 */
class GroundednessSignal
{
    public function isLow(Collection $results): bool
    {
        return $results->isEmpty();
    }
}
