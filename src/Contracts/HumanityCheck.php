<?php

namespace Splicewire\Beam\Embed\Contracts;

use Illuminate\Http\Request;

/**
 * The bot-gate seam (DIE-10 / ADR-0062 decision 7) in front of issuing a short-lived
 * Visitor session token. A public, unauthenticated, LLM-backed endpoint is a
 * cost-bomb / DoS vector; this is where a challenge (Cloudflare Turnstile) plugs in.
 *
 * Seam, not premature infra: the default is a null pass-through for dev; a Turnstile
 * driver ships but is not provisioned until launch.
 */
interface HumanityCheck
{
    public function passes(Request $request): bool;
}
