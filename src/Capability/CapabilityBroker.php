<?php

namespace Splicewire\Beam\Embed\Capability;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Contracts\CapabilityHandler;
use Splicewire\Beam\Embed\Types\EmbedType;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Embed\Types\GenericEmbedType;

/**
 * The host-side authority for the brokered-capability surface (RCP-05), generalized DOWN to beam-core
 * (recohere T30). An isolated remote guest, holding only its scoped token, asks the host to run a named
 * capability; this broker is the ONLY path that authority flows through. For every call it owns the
 * INVARIANT envelope — the part that is identical for every embed type:
 *
 *   1. verifies the token's signature + audience (the token was minted for THIS embed),
 *   2. enforces expiry (short-TTL),
 *   3. checks the grant permits the requested capability (tier-bounded),
 *   4. dispatches the actual work to the capability HANDLER the active embed TYPE registered,
 *   5. audits the call — allowed or refused — through the {@see CapabilityAuditRecorder} port.
 *
 * A capability outside the grant, a cross-audience token, an expired token, or a bad signature is
 * REFUSED here, before any handler runs. Scope enforcement and the actual server-side work (dereference,
 * scoped read, save) live in the type's handlers, NOT here — that is where the knowledge/host coupling
 * belongs. beam-embed ships deny-by-default handlers (the {@see GenericEmbedType});
 * tower's `concierge` type registers Fragment-backed handlers. The broker never references a corpus.
 *
 * A direct credentialed call is impossible: the guest never holds a credential, only this token, and
 * this broker runs the privileged work through the type's handler.
 */
class CapabilityBroker
{
    public function __construct(
        protected EmbedTypeRegistry $types,
        protected CapabilityAuditRecorder $auditRecorder,
        protected Container $container,
    ) {}

    /**
     * Broker one capability call. $token is the raw JWT the guest holds; $expectedAudience is the
     * embed_key id resolved from the request's `pk_` (so a token for embed A on embed B's request is
     * refused). $embedType selects the type whose handlers service the call; null = the active type.
     *
     * @param  array<string, mixed>  $payload
     */
    public function invoke(
        string $token,
        string $expectedAudience,
        string $capabilityName,
        array $payload,
        ?string $embedType = null,
    ): BrokerResult {
        $capability = Capability::tryFromName($capabilityName);

        $verified = CapabilityToken::verify($token, $expectedAudience);

        // Signature / audience failure — we cannot trust ANY claim, so we audit with what we safely
        // know (the audience the request resolved to) and refuse.
        if ($verified === null) {
            return $this->refuse($expectedAudience, null, $capabilityName, 'invalid_token', $payload, 401);
        }

        $grant = $verified->grant;

        if ($verified->isExpired()) {
            return $this->refuse($grant->embedKeyId, $grant, $capabilityName, 'expired', $payload);
        }

        if ($capability === null) {
            return $this->refuse($grant->embedKeyId, $grant, $capabilityName, 'unknown_capability', $payload, 422);
        }

        if (! $grant->permits($capability)) {
            // Over-ask: the capability is real but outside this grant/tier.
            return $this->refuse($grant->embedKeyId, $grant, $capabilityName, 'capability_not_granted', $payload);
        }

        $handler = $this->handlerFor($this->types->get($embedType), $capability);

        if ($handler === null) {
            return $this->refuse($grant->embedKeyId, $grant, $capability->value, 'capability_unsupported', $payload, 501);
        }

        $result = $handler->handle($grant, $payload);

        // The type's handler decides allow/refuse (scope, resolvability); the broker audits the outcome
        // uniformly so every embed type gets the same durable record.
        $this->audit($grant->embedKeyId, $grant, $capability->value, $result->allowed, $result->reason, $payload);

        return $result;
    }

    /**
     * Resolve the handler the given type registered for a capability, container-resolving a class-string.
     */
    protected function handlerFor(EmbedType $type, Capability $capability): ?CapabilityHandler
    {
        $handler = $type->capabilityHandlers()[$capability->value] ?? null;

        if ($handler === null) {
            return null;
        }

        return $handler instanceof CapabilityHandler
            ? $handler
            : $this->container->make($handler);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function refuse(string $embedKeyId, ?CapabilityGrant $grant, string $capability, string $reason, array $payload, int $status = 403): BrokerResult
    {
        $this->audit($embedKeyId, $grant, $capability, allowed: false, reason: $reason, payload: $payload);

        return BrokerResult::refuse($reason, $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function audit(string $embedKeyId, ?CapabilityGrant $grant, string $capability, bool $allowed, ?string $reason, array $payload): void
    {
        $this->auditRecorder->record($embedKeyId, $grant, $capability, $allowed, $allowed ? null : $reason, $payload);
    }
}
