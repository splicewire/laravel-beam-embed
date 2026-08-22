<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Threads\Enums\ParticipantKind;
use Splicewire\Beam\Threads\Models\Message;

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
 * public intake door takes, so the write goes through {@see \Splicewire\Beam\Write\ParticleWriter} and
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
     */
    public function escalate(Conversation $session, string $reason, ?array $contact = null, bool $consent = false): BeamSubmission
    {
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

        // The five arguments this has always passed. `?array $schema` and `?IntakeProvenance` stay
        // unpassed: a null `schemaRef` means the notify path resolves nothing for an escalation
        // either way, and making one addressable is its own question (beam-facade ticket 81).
        return $this->submissions->record(
            formKey: 'embed/escalation',
            schemaRef: null,
            payload: $payload,
            context: [
                'kind' => 'embed_escalation',
                'session_id' => $session->id,
                'visitor_id' => $session->visitor_id,
                'published_from_id' => $session->published_from_id,
            ],
        );
    }
}
