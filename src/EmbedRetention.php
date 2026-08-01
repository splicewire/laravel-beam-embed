<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Enums\ThreadKind;
use Splicewire\Beam\Embed\Models\Embed;
use Splicewire\Beam\Models\ChatBase;
use Splicewire\Beam\Embed\Models\Visitor;

/**
 * Retention + erasure for embed sessions (DIE-11 / ADR-0062 decision 8). A public
 * surface collects the tenant's end-customers' messages (PII): tenant = controller,
 * platform = processor, so this must be real, not aspirational.
 *
 * Retention is a per-Published-Chat window (snapshotted onto each session as
 * `retention_days`, default 90); a session past its window is pruned with its
 * messages. Erasure is keyed by `visitor_id` or by session id. Transcripts are never
 * fed into corpus/training without an explicit per-Embed opt-in ({@see corpusOptIn()}).
 */
class EmbedRetention
{
    public const DEFAULT_DAYS = 90;

    /**
     * Delete every embed session (and its messages) past its retention window.
     */
    public function prune(): int
    {
        $count = 0;

        $this->eachExpiredSession(function (ChatBase $session) use (&$count) {
            $count += $this->erase($session);
        });

        return $count;
    }

    /**
     * Count the sessions {@see prune()} WOULD delete, without deleting them — the dry-run
     * preview so the operator sees the blast radius before executing a prune.
     */
    public function pruneableCount(): int
    {
        $count = 0;

        $this->eachExpiredSession(function () use (&$count) {
            $count++;
        });

        return $count;
    }

    /**
     * Walk every embed session past its retention window, in id-chunks (safe to delete within,
     * {@see prune()} does). The one scan both the executing prune and its dry-run preview share.
     */
    private function eachExpiredSession(callable $each): void
    {
        ChatBase::query()
            ->where('kind', ThreadKind::EmbedSession->value)
            ->chunkById(200, function ($sessions) use ($each) {
                foreach ($sessions as $session) {
                    if ($this->isExpired($session)) {
                        $each($session);
                    }
                }
            });
    }

    /**
     * The per-Published-Chat retention posture: each embed template with its effective retention
     * window (its own snapshotted policy, else the platform {@see DEFAULT_DAYS}) and whether it opted
     * transcripts into corpus/training. A read-only policy enumeration — the honest data behind the
     * console's former "windows shown here soon" / "consent isn't surfaced yet" stubs.
     *
     * @return list<array{id: string, title: ?string, retention_days: int, corpus_optin: bool}>
     */
    public function posture(): array
    {
        return Embed::query()
            ->orderBy('title')
            ->get()
            ->map(fn (Embed $embed) => [
                'id' => (string) $embed->getKey(),
                'title' => $embed->title,
                // retention_days is a real column on the template (set at publish, EmbedPublisher),
                // snapshotted onto each session; NOT an embed_policy key. Corpus consent, by contrast,
                // does live in embed_policy (see corpusOptIn()).
                'retention_days' => (int) ($embed->retention_days ?? self::DEFAULT_DAYS),
                'corpus_optin' => (bool) ($embed->embed_policy['corpus_optin'] ?? false),
            ])
            ->all();
    }

    /** Whether a session has aged past its snapshotted retention window. */
    private function isExpired(ChatBase $session): bool
    {
        $days = $session->retention_days ?? self::DEFAULT_DAYS;

        return $session->created_at !== null && $session->created_at->lt(now()->subDays($days));
    }

    /** Erase all sessions owned by a visitor. */
    public function eraseByVisitor(string $visitorId): int
    {
        $count = 0;

        ChatBase::query()
            ->where('kind', ThreadKind::EmbedSession->value)
            ->where('visitor_id', $visitorId)
            ->get()
            ->each(function (ChatBase $session) use (&$count) {
                $count += $this->erase($session);
            });

        // The visitor is the erasure subject: drop its row (ip/ua/session-token
        // hashes) even if it had no sessions to erase.
        Visitor::query()->whereKey($visitorId)->delete();

        return $count;
    }

    /** Erase one session by id. */
    public function eraseBySession(string $sessionId): int
    {
        $session = ChatBase::query()
            ->where('kind', ThreadKind::EmbedSession->value)
            ->find($sessionId);

        return $session !== null ? $this->erase($session) : 0;
    }

    /** Whether this Published Chat opted transcripts into corpus/training. Default: no. */
    public function corpusOptIn(ChatBase $published): bool
    {
        return (bool) ($published->embed_policy['corpus_optin'] ?? false);
    }

    private function erase(ChatBase $session): int
    {
        $visitorId = $session->visitor_id;

        $session->messages()->delete();
        $session->delete();

        // Prune the visitor's PII once its last session is gone (prune / erase-by-session
        // paths); a visitor with other live sessions is left intact.
        if ($visitorId !== null && ! ChatBase::query()->where('visitor_id', $visitorId)->exists()) {
            Visitor::query()->whereKey($visitorId)->delete();
        }

        return 1;
    }
}
