<?php

namespace Splicewire\Beam\Embed\Tests\Fakes;

use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Contracts\CapabilityAuditRecorder;

/**
 * An in-memory {@see CapabilityAuditRecorder} — the audit port's whole surface is "a call happened",
 * so a recording fake is a truthful stand-in and keeps the broker's tests off the database entirely
 * (the real recorder writes an `embed_capability_audits` row, which is a tenant table).
 */
class RecordingAuditRecorder implements CapabilityAuditRecorder
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function record(
        string $embedKeyId,
        ?CapabilityGrant $grant,
        string $capability,
        bool $allowed,
        ?string $reason,
        array $payload,
    ): void {
        $this->records[] = compact('embedKeyId', 'grant', 'capability', 'allowed', 'reason', 'payload');
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->records === [] ? null : $this->records[count($this->records) - 1];
    }
}
