<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Contracts\Conversation;
use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Models\BeamSubmission;

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

        $transcript = $session->messages()->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content_text])
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
