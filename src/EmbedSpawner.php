<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use Splicewire\Beam\Contracts\Conversation;
use Splicewire\Beam\Embed\Contracts\KnowledgePivotFreezer;
use Splicewire\Beam\Embed\Models\BaseThread;
use Splicewire\Beam\Embed\Models\Visitor;
use Splicewire\Beam\Threads\Enums\ParticipantRole;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Models\Participant;

/**
 * Spawns a visitor-owned `embed_session` from a published template (DIE-04 / ADR-0062). The effective
 * config is **snapshotted at spawn** — the provenance guarantee — so a later edit to the published chat
 * never changes a live or past session's config ("reference live" was rejected). Owned by a
 * {@see Visitor}, `user_id` is null.
 *
 * The staged conversation config is read from the template's **published HEAD version** (ticket 13),
 * NOT its live working columns: an operator's unpublished edit never reaches a visitor.
 *
 * Recohere T02: the spawner is tower-ref-free. Knowledge pivots (fragments/silos/contextScopes) are
 * NOT on the generic conversation particle, so they are frozen through the injected {@see KnowledgePivotFreezer}
 * port — a no-op on a bare beam site, the real tower-Thread copy when tower-embed binds its freezer.
 */
class EmbedSpawner
{
    public function __construct(private KnowledgePivotFreezer $freezer) {}

    public function spawn(Conversation $published, Visitor $visitor): Conversation
    {
        if (! $published->isPublished()) {
            throw new InvalidArgumentException('Can only spawn a session from a published (embed_template) chat.');
        }

        // Re-resolve the template as the BASE conversation model ({@see BaseThread}) so the version HEAD is
        // read off the base `thread` morph, never the `embed` permission-alias lineage. The session below is
        // created through this same base model, so it too carries the base morph. TH-08 phase 5 (B2): the
        // former `config('embed.base_model')` host seam is retired for beam-embed's own base-morph model.
        /** @var class-string<Model&Conversation> $base */
        $base = BaseThread::class;
        if (! $published instanceof $base) {
            $published = $base::query()->findOrFail($published->getKey());
        }

        $head = $published->headVersion();

        if ($head === null) {
            throw new RuntimeException(
                "Published embed [{$published->id}] has no version HEAD; re-publish it before spawning.",
            );
        }

        $snapshot = $head->snapshot;

        $config = [
            'assistant_id' => $snapshot['assistant_id'] ?? null,
            'title' => $snapshot['title'] ?? null,
            'model' => $snapshot['model'] ?? null,
            'max_tool_steps' => $snapshot['max_tool_steps'] ?? null,
            'model_params' => $snapshot['model_params'] ?? null,
            'instructions_provider' => $snapshot['instructions_provider'] ?? null,
            'instructions_text' => $snapshot['instructions_text'] ?? null,
            'instructions_schemas' => $snapshot['instructions_schemas'] ?? null,
            'tools' => $snapshot['tools'] ?? null,
        ];

        /** @var Conversation $session */
        $session = $published->newInstance()->newQuery()->create(array_merge($config, [
            'kind' => ThreadKind::EmbedSession,
            'user_id' => null,
            'visitor_id' => $visitor->id,
            'published_from_id' => $published->id,
            'template_version' => $published->template_version,
            'snapshot_config' => $config,
            'retention_days' => $published->retention_days,
            'session_status' => 'bot',
        ]));

        // A session is visitor-owned: guarantee it has NO owner-User participant even if a User is
        // authenticated at spawn time (TH-07 header migration phase 4 — ownership is an owner Participant,
        // not a `user_id` column). The HasUserId creating-hook may have stamped an owner from the current
        // auth, so drop any owner-User participant on the freshly-spawned session outright.
        Participant::query()
            ->where('thread_id', $session->getKey())
            ->where('role', ParticipantRole::Owner->value)
            ->where('actor_type', 'user')
            ->delete();

        // Freeze knowledge grounding through the inversion port (T02).
        $this->freezer->freeze($session, $published);

        return $session;
    }
}
