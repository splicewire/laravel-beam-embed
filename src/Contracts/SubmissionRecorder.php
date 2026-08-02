<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Models\BeamSubmission;

/**
 * The escalation-handoff INVERSION port (recohere T02). {@see \Splicewire\Beam\Embed\EscalationService}
 * hands a human-exit off through the submission / intake path — a {@see BeamSubmission} (a beam
 * BeamParticle) whose persist emits `BeamParticlePersisted`, which the tenant's chosen intent delivers.
 *
 * The tower orchestrator that actually runs that write (`Splicewire\Tower\Submissions\RecordsSubmissions`) depends
 * on tower-only classes (the central form registry, the write/acceptance gates), so it cannot move DOWN
 * into beam-embed without dragging tower up. It is inverted behind this port instead:
 *
 *  - a bare beam site binds a null recorder that persists a plain {@see BeamSubmission} directly, so an
 *    escalation still records;
 *  - tower-embed binds a recorder delegating to `RecordsSubmissions`, so the full notify/intent path fires.
 *
 * The signature mirrors the tower `RecordsSubmissions::record()` it fronts, so the two stay drop-in.
 */
interface SubmissionRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $formKey,
        ?string $schemaRef,
        array $payload,
        array $context = [],
        ?string $userId = null,
    ): BeamSubmission;
}
