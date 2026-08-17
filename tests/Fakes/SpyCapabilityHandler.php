<?php

namespace Splicewire\Beam\Embed\Tests\Fakes;

use Splicewire\Beam\Embed\Capability\BrokerResult;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Contracts\CapabilityHandler;

/**
 * A capability handler that records what the broker handed it and returns a caller-chosen outcome.
 * Stands in for a host type's corpus-backed handler so the broker's invariant envelope can be tested
 * without any knowledge/host coupling — which is exactly the separation the broker claims to have.
 */
class SpyCapabilityHandler implements CapabilityHandler
{
    public int $calls = 0;

    public ?CapabilityGrant $receivedGrant = null;

    /** @var array<string, mixed>|null */
    public ?array $receivedPayload = null;

    public function __construct(private ?BrokerResult $outcome = null) {}

    public function handle(CapabilityGrant $grant, array $payload): BrokerResult
    {
        $this->calls++;
        $this->receivedGrant = $grant;
        $this->receivedPayload = $payload;

        return $this->outcome ?? BrokerResult::allow(['ok' => true]);
    }
}
