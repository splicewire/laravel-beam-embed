<?php

namespace Splicewire\Beam\Embed\Types\Handlers;

use Splicewire\Beam\Embed\Capability\BrokerResult;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Contracts\CapabilityHandler;
use Splicewire\Beam\Embed\Types\GenericEmbedType;

/**
 * The deny-by-default capability handler for the {@see GenericEmbedType}
 * (recohere T30). A bare beam substrate has no corpus to resolve/read and no host save path, so its
 * remote-embed type answers every brokered call with a refusal — the machinery is fully wired
 * (a real registry, a real broker, a real audited call), it simply has nothing to serve until a host
 * embed type (tower's `concierge`) registers handlers that reach a corpus.
 *
 * This keeps the deny-by-default posture honest: substrate-only means brokered reads return nothing,
 * exactly as an empty scope already does at the broker's scope gate.
 */
class UnsupportedCapabilityHandler implements CapabilityHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(CapabilityGrant $grant, array $payload): BrokerResult
    {
        return BrokerResult::refuse('capability_unsupported', 501);
    }
}
