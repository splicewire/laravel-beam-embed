<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\DefaultCapabilityAuditRecorder;
use Splicewire\Beam\Embed\Models\EmbedCapabilityAudit;
use Splicewire\Beam\Embed\Types\EmbedType;

/**
 * The audit-write INVERSION port (recohere T30) — the {@see CapabilityBroker}'s WRITE BOUNDARY: the
 * point at which the invariant envelope stops and a durable-record policy begins. Every
 * brokered-capability call — allowed or refused — is recorded so there is a durable record of exactly
 * what an isolated remote guest asked the host to run, and the generic broker delegates that write
 * through this port rather than touching a model, so the class the architecture requires to stay
 * generic never carries a tenant-connection write:
 *
 *  - beam-embed binds the {@see DefaultCapabilityAuditRecorder}, which writes the
 *    plain {@see EmbedCapabilityAudit} row — the correct baseline for any
 *    embed type;
 *  - a host MAY bind a recorder that additionally weaves the call into its own integration on top of
 *    that base row. None does today (beam-facade ticket 80).
 *
 * The recorder receives the verified grant (null on an untrusted/forged token, where no claim can be
 * trusted) plus the resolved embed-key audience so the row is always attributable.
 *
 * WHY IT STANDS, and the rationale that does NOT apply (beam-facade ticket 80, which re-asked 40's
 * rule — *a seam with one implementation and no rebinder is not a seam*). It stands on SUBSTITUTION
 * rather than on bindings: its live substitute is this package's own `RecordingAuditRecorder`, which
 * is what keeps every case in `CapabilityBrokerTest` — including each refusal branch: forged, expired,
 * over-ask, unknown, unsupported — off a tenant-connection database. It does NOT stand on anticipating
 * a second embed TYPE: audit is cross-cutting by {@see EmbedType}'s own declaration ("a type composes
 * them; it does not redeclare them"), so a second type does not imply a second recorder, and that
 * rationale is struck rather than weighed. There is deliberately NO deletion condition here — this is
 * substrate for the remote-widget surface. RE-ASK ONLY IF the broker stops being the sole authority
 * path, or a host binds a recorder that reveals this boundary is in the wrong place.
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
