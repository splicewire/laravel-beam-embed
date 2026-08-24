<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Intake\IntakeProvenance;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Models\Message;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The exit to a human (DIE-09 / ADR-0062 decision 5): "Support" reuses existing delivery infra rather
 * than building an agent inbox — deflect-first.
 *
 * An escalation captures the reason (low groundedness or explicit visitor request), the transcript, and
 * — only on explicit consent — the visitor's contact, then hands off through the submission / intake path
 * (a {@see BeamSubmission} whose persist emits BeamParticlePersisted, delivered by the tenant's chosen
 * intent). The Embed Session's status flips to `escalated` and the handoff is recorded.
 *
 * The handoff write rides beam-core's {@see RecordsSubmissions} directly — the same capture path a
 * public intake door takes, so the write goes through {@see ParticleWriter} and
 * emits `BeamParticlePersisted`, the one post-persist signal every beam write path shares.
 *
 * It used to sit behind a `SubmissionRecorder` inversion port (recohere T02), whose bare-site default
 * saved the model straight through. That port is deleted (beam-facade ticket 63): its justification was
 * that the real orchestrator was unreachable from here, ticket 49 removed that obstacle, and the two
 * surviving implementations then differed in exactly one thing — whether the write emitted. Emitting is
 * not a host policy, and the bare-site default's own docblock claimed it emitted on save, which was
 * never true: nothing observes model save, only `EmitStage` dispatches. So the port fronted a defect,
 * not a choice.
 */
class EscalationService
{
    public function __construct(private RecordsSubmissions $submissions) {}

    /**
     * @param  array<string, mixed>|null  $contact
     * @param  ?IntakeProvenance  $intake  the visitor's ip / user-agent / submitted-at, supplied by the
     *                                     host controller that owns the Request (beam-facade ticket 81).
     */
    public function escalate(
        Conversation $session,
        string $reason,
        ?array $contact = null,
        bool $consent = false,
        ?IntakeProvenance $intake = null,
    ): BeamSubmission {
        $session->update(['session_status' => 'escalated']);

        // TH-08 message-layer cutover: the transcript reads the substrate {@see Message} particle — role is
        // DERIVED from the author participant's kind (the substrate carries no `role` column; authorship is
        // the participant), and content is the coalesced-text accessor (no `content_text` column).
        $transcript = $session->messages()->with('participant')->get()
            ->map(fn (Message $m) => [
                'role' => match ($m->participant?->kind instanceof ParticipantKind
                    ? $m->participant->kind
                    : ParticipantKind::tryFrom((string) $m->participant?->kind)) {
                    ParticipantKind::Human => 'user',
                    ParticipantKind::Agent => 'assistant',
                    default => 'system',
                },
                'content' => (string) $m->content,
            ])
            ->all();

        $payload = ['reason' => $reason, 'transcript' => $transcript];

        // Contact is captured ONLY on explicit consent.
        if ($consent && $contact !== null && $contact !== []) {
            $payload['contact'] = $contact;
        }

        // ADDRESSABLE, when the host says so (beam-facade ticket 81). An escalation used to pass
        // `schemaRef: null`, which made `RegistrySchemaResolver` skip tier 1, find no snapshot, return
        // `[]`, and `NotifyOnSubmission` return immediately — so the one write in this subsystem whose
        // purpose is REACH A HUMAN could not reach one, by construction.
        //
        // The ref is host config rather than a constant here, because the `$id`'s authority belongs to
        // the host that mints it (`data-schemas.base_uri`) and this package must not stamp one vendor's
        // domain onto every host's records. Unset = the old unaddressable behaviour, unchanged.
        //
        // A configured ref that the registry cannot answer THROWS `UnresolvableSchemaRef` (ticket 62),
        // so the artifact must be registered before this is set — see the audit ticket 81 spawned.
        //
        // `?array $schema` still stays unpassed: a snapshot is the stale read for any addressable
        // record (ticket 47), so an escalation carrying a ref must resolve through the registry only.
        $schemaRef = config('embed.escalation.schema_ref');

        return $this->submissions->record(
            captureKey: 'embed/escalation',
            schemaRef: is_string($schemaRef) && $schemaRef !== '' ? $schemaRef : null,
            payload: $payload,
            intake: $intake,
            context: [
                'kind' => 'embed_escalation',
                'session_id' => $session->id,
                'visitor_id' => $session->visitor_id,
                'published_from_id' => $session->published_from_id,
            ],
        );
    }
}
