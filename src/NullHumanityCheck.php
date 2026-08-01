<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Contracts\HumanityCheck;
use Illuminate\Http\Request;

/**
 * The dev/default {@see HumanityCheck}: always passes. No challenge is provisioned
 * until launch (seams, not premature infra) — the Turnstile driver is the escape hatch.
 */
class NullHumanityCheck implements HumanityCheck
{
    public function passes(Request $request): bool
    {
        return true;
    }
}
