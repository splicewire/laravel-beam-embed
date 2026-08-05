<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Models\BeamSubmission;
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
 * Recohere T02: the handoff write is inverted behind the {@see SubmissionRecorder} port — a plain
 * BeamSubmission on a bare beam site, the full tower RecordsSubmissions path when tower-embed binds it.
 */
class EscalationService
{
    public function __construct(private ?SubmissionRecorder $submissions = null) {}

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

        return ($this->submissions ??= app(SubmissionRecorder::class))->record(
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
