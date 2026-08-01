<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Contracts\SubmissionRecorder;
use Splicewire\Beam\Models\BeamSubmission;

/**
 * The default {@see SubmissionRecorder} bound on a bare beam site (recohere T02): persists a plain
 * {@see BeamSubmission} directly through the model. It records the escalation (and, because BeamSubmission
 * is a beam BeamParticle, emits the same post-persist signal on save) but does NOT run the tower
 * form-registry / notify wiring — that arrives when tower-embed binds its RecordsSubmissions-backed recorder.
 */
class NullSubmissionRecorder implements SubmissionRecorder
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
    ): BeamSubmission {
        $submission = new BeamSubmission([
            'form_key' => $formKey,
            'schema_ref' => $schemaRef,
            'context' => $context,
            'user_id' => $userId,
        ]);

        $submission->setAttribute('payload', $payload);
        $submission->save();

        return $submission;
    }
}
