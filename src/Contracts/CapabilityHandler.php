<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Embed\Capability\BrokerResult;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;

/**
 * One brokered capability's server-side execution (RCP-05), the per-capability seam an embed TYPE
 * plugs into. The generic {@see CapabilityBroker} owns the invariant
 * envelope — token verify, expiry, tier/grant check, audit — and, once a call is authorized, dispatches
 * the actual work to the handler the ACTIVE embed type registered for that capability.
 *
 * This is where the knowledge/host coupling lands: beam-embed ships deny-by-default handlers (a bare
 * substrate has nothing to resolve), and a host embed type (tower's `concierge`) registers handlers that
 * reach its own corpus — the Fragment dereference in `resolve`, the scoped Fragment read in `read_scoped`.
 * The broker never knows about Fragment/Thread; it only knows this contract.
 *
 * A handler executes AFTER the grant already permits the capability and (for scope-bound capabilities)
 * the caller has satisfied the frozen scope; a handler may still refuse for capability-specific reasons
 * (missing ref, out-of-scope ref, unresolvable) via {@see BrokerResult::refuse()}.
 */
interface CapabilityHandler
{
    /**
     * Execute the capability for an authorized grant.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(CapabilityGrant $grant, array $payload): BrokerResult;
}
