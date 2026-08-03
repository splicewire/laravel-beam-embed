<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\DefaultCapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\EmbedCapabilityAudit;

/**
 * The audit-write INVERSION port (recohere T30). Every brokered-capability call — allowed or refused —
 * is recorded so there is a durable record of exactly what an isolated remote guest asked the host to
 * run. The GENERIC broker delegates that write through this port instead of touching a model directly,
 * so the audit surface is a seam an embed TYPE can extend:
 *
 *  - beam-embed binds the {@see DefaultCapabilityAuditRecorder}, which writes the
 *    plain {@see EmbedCapabilityAudit} row — the correct baseline for any
 *    embed type;
 *  - a host (tower's concierge type) MAY bind a recorder that additionally weaves the call into its own
 *    Fragment/Thread integration on top of the base row.
 *
 * The recorder receives the verified grant (null on an untrusted/forged token, where no claim can be
 * trusted) plus the resolved embed-key audience so the row is always attributable.
 */
interface CapabilityAuditRecorder
{
    /**
     * Record one brokered-capability invocation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $embedKeyId,
        ?CapabilityGrant $grant,
        string $capability,
        bool $allowed,
        ?string $reason,
        array $payload,
    ): void;
}
