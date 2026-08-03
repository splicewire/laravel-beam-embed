<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\EmbedCapabilityAudit;

/**
 * The default {@see CapabilityAuditRecorder} (recohere T30): write the plain
 * {@see EmbedCapabilityAudit} row — the correct baseline for ANY embed type. This is the exact write
 * that used to live inline in the tower CapabilityBroker's `audit()` method before the broker was
 * generalized DOWN. A host embed type may bind its own recorder to weave the call into further
 * integration on top of this base row.
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
