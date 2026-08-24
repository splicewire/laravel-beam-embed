<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\EmbedCapabilityAudit;
use Splicewire\Beam\Embed\Types\EmbedType;

/**
 * The default {@see CapabilityAuditRecorder} (recohere T30): write the plain
 * {@see EmbedCapabilityAudit} row — the correct baseline for ANY embed type. This is the exact write
 * that used to live inline in the tower CapabilityBroker's `audit()` method before the broker was
 * generalized DOWN. A host may bind its own recorder to weave the call into further integration on
 * top of this base row; none does today.
 *
 * `Default*`, not `Null*`, and the prefix is load-bearing: this performs the correct baseline write
 * rather than an honest absence, which is the distinction beam-facade ticket 63 turned on. Note what
 * this class does NOT know that its caller does not — it is a field map of the six arguments the
 * broker already holds, so the port it implements stands on the broker's WRITE BOUNDARY and on
 * substitution, never on knowledge (62's amendment to 40) and never on anticipating a second embed
 * TYPE (struck at ticket 80; audit is cross-cutting by {@see EmbedType}'s own declaration). The
 * argument in full, and the condition for re-asking it, live on {@see CapabilityAuditRecorder}.
 *
 * The tier for the audit comes from the verified grant; on a bad/forged token there is no trustworthy
 * grant, so it records the tier as 'unknown'.
 */
class DefaultCapabilityAuditRecorder implements CapabilityAuditRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $embedKeyId,
        ?CapabilityGrant $grant,
        string $capability,
        bool $allowed,
        ?string $reason,
        array $payload,
    ): void {
        EmbedCapabilityAudit::create([
            'embed_key_id' => $embedKeyId,
            'session_id' => $grant?->sessionId,
            'capability' => $capability,
            'tier' => $grant?->tier->value ?? 'unknown',
            'allowed' => $allowed,
            'refusal_reason' => $allowed ? null : $reason,
            'request' => $payload,
        ]);
    }
}
