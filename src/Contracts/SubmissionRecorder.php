<?php

namespace Splicewire\Beam\Embed\Contracts;

use Splicewire\Beam\Embed\EscalationService;
use Splicewire\Beam\Embed\NullSubmissionRecorder;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Submissions\RecordsSubmissions;

/**
 * The escalation-handoff INVERSION port (recohere T02). {@see EscalationService}
 * hands a human-exit off through the submission / intake path — a {@see BeamSubmission} (a beam
 * BeamParticle) whose persist emits `BeamParticlePersisted`, which the tenant's chosen intent delivers.
 *
 * WHY THIS PORT EXISTS IS NO LONGER TRUE (beam-facade ticket 49). It read: "the tower orchestrator
 * that actually runs that write (`Splicewire\Tower\Submissions\RecordsSubmissions`) depends on
 * tower-only classes (the central form registry, the write/acceptance gates), so it cannot move DOWN
 * into beam-embed without dragging tower up." That orchestrator was a FORK of beam-core's
 * {@see RecordsSubmissions} and all three tower-only collaborators are
 * deleted; the real one lives in beam-core, which this package already requires. So nothing stops a
 * pipeline-backed recorder shipping HERE as the default, and the current two implementations —
 *
 *  - {@see NullSubmissionRecorder}, which saves a plain {@see BeamSubmission}
 *    straight through the model, and
 *  - tower-embed's `TowerSubmissionRecorder`, which now just delegates to beam-core
 *
 * — differ only in whether the write rides the pipeline, which is not a host policy. Deliberately NOT
 * collapsed here: that is a change to what a bare beam site does on escalation, and it earns its own
 * ticket rather than riding a de-fork.
 *
 * The signature mirrors `RecordsSubmissions::record()`'s first five parameters, so the two stay drop-in.
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
