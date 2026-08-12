<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Embed\Models\Thread;
use Splicewire\Beam\Embed\Models\EmbedKey;
use Splicewire\Beam\Threads\Enums\ThreadKind;

/**
 * "Publish as embed" (DIE-04 / ADR-0062): flips a conversation to an `embed_template`,
 * attaches its embed policy (origin allowlist, retention, optional wallet ref), and
 * mints the central Embed Key pointer (issue 03) so the drop-in resolves back to
 * this tenant + published chat. The config IS the Chat — there is no separate Embed
 * Instance model.
 */
class EmbedPublisher
{
    /**
     * The ONLY launcher keys that ride the `embed_policy` JSON and get projected to
     * the drop-in via `/embed/config`. Cosmetic, every one — a leaked public `pk_`
     * that reads them learns nothing capability-bearing. This is the single shared
     * whitelist: `publish()` filters incoming settings through it here, and the config
     * projection filters the stored block through it again on the way out.
     *
     * @var list<string>
     */
    public const LAUNCHER_COSMETIC_KEYS = [
        'enabled',
        'position',
        'accentColor',
        'iconUrl',
        'greeting',
        'title',
    ];

    /**
     * @param  list<string>  $allowedOrigins
     * @param  array<string, mixed>|null  $launcher  Optional launcher cosmetics; only the
     *                                               whitelisted keys survive — unknown keys are dropped.
     * @param  bool  $selfDeliver  Mint a first-party (self-delivered) key whose plaintext is stored
     *                             so the app can read it back at `/me` (the in-app support widget).
     *                             Default false — third-party embeds stay hash-only / show-once.
     * @return array{plaintext: string, key: EmbedKey}
     */
    public function publish(
        Conversation $chat,
        array $allowedOrigins = [],
        ?int $retentionDays = null,
        ?float $walletCreditUsd = null,
        bool $enabled = true,
        ?array $launcher = null,
        bool $selfDeliver = false,
    ): array {
        $policy = array_filter([
            'allowed_origins' => $allowedOrigins,
            // Per-Published-Chat prepaid wallet + kill-switch (DIE-06).
            'wallet_credit_usd' => $walletCreditUsd,
            'enabled' => $enabled,
        ], fn ($v) => $v !== null && $v !== []);

        // Whitelist launcher cosmetics into the existing JSON column (no migration).
        // Unknown keys are dropped; capability-bearing settings can never sneak in.
        if ($launcher !== null) {
            $cosmetic = Arr::only($launcher, self::LAUNCHER_COSMETIC_KEYS);
            if ($cosmetic !== []) {
                $policy['launcher'] = $cosmetic;
            }
        }

        $chat->update([
            'kind' => ThreadKind::EmbedTemplate,
            'template_version' => ($chat->template_version ?? 0) + 1,
            'embed_policy' => $policy,
            'retention_days' => $retentionDays,
        ]);

        // Snapshot the conversation config as version 1 (ticket 13): this establishes the HEAD the
        // spawner reads, so a freshly-published embed serves its published copy from the first spawn
        // (no live-fields fallback). Taken on the base `thread` morph so the spawner can read it back.
        $this->baseThread($chat)->snapshotVersion('published');

        [$key, $plaintext] = EmbedKey::issue((string) tenant()?->getTenantKey(), $chat->id, $allowedOrigins, $selfDeliver);

        return ['plaintext' => $plaintext, 'key' => $key];
    }

    /**
     * Stage an edit to a published embed's conversation config on the working row WITHOUT publishing
     * it (ticket 13). HEAD does not move, so live visitors keep seeing the published copy; the working
     * row now sits ahead of HEAD → the surface's "changes pending" chip lights up. Publishing the edit
     * is a separate, deliberate act ({@see republish()}). Only the staged conversation-config fields
     * are writable here — operational policy travels {@see updateConfig()}, and only whitelisted keys
     * ever land.
     *
     * @param  array<string, mixed>  $fields  Any of: instructions_provider, instructions_text,
     *                                        instructions_schemas, model, title, max_tool_steps.
     */
    public function updateInstructions(Conversation $chat, array $fields): void
    {
        $staged = Arr::only($fields, self::STAGED_INSTRUCTION_KEYS);

        if ($staged !== []) {
            $chat->update($staged);
        }
    }

    /**
     * The conversation-config keys the edit-instructions endpoint may stage (ticket 13, payload W3).
     * A subset of the conversation particle's `toVersionSnapshot()` — the operator-editable instruction fields; the
     * heavier `model_params` / `tools` / `agent_id` are edited through the agent editor, not
     * this slice, but still ride the published snapshot whatever their current value.
     *
     * @var list<string>
     */
    public const STAGED_INSTRUCTION_KEYS = [
        'instructions_provider',
        'instructions_text',
        'instructions_schemas',
        'model',
        'title',
        'max_tool_steps',
    ];

    /**
     * Publish the working row's staged conversation config (ticket 13): bump `template_version` and
     * freeze a new version, advancing HEAD so subsequent spawns serve the edited copy. Deliberately
     * NARROWER than {@see publish()} — it snapshots and version-bumps but does NOT mint a new key or
     * touch `embed_policy` (re-publishing content must never rotate the key or reset operational
     * safety). The returned {@see Version} is the freshly-minted HEAD.
     */
    public function republish(Conversation $chat, ?string $label = null): Version
    {
        $chat->update(['template_version' => ($chat->template_version ?? 0) + 1]);

        return $this->baseThread($chat)->snapshotVersion($label);
    }

    /**
     * Re-resolve a DB-fresh BASE-conversation instance for a version WRITE. Two reasons it always
     * reloads rather than snapshotting the passed model:
     *
     * 1. **Morph.** Versioning keys off `getMorphClass()`; an {@see Embed} (morph `embed`, the
     *    permission alias) would file the version under the empty `embed` lineage while the spawner
     *    reads the base `thread` morph. Reloading the BASE model pins the version to the base morph.
     * 2. **DB defaults.** Snapshotting a just-created / just-mutated *in-memory* row freezes NULLs for
     *    columns the DB fills by default, so its `_hash` would never match a later DB-fresh read of
     *    the same row — the "changes pending" chip would light the instant an embed is published. A
     *    fresh read captures the persisted state (the same footgun Composition's provision-seed hits).
     *
     * The base model is the host's base conversation class (recohere T02): `config('embed.base_model')`,
     * which tower binds to `Splicewire\Tower\Models\Thread` so the morph stays `thread`. TH-07 deleted the
     * beam-core `ChatBase` fallback — the host MUST bind it (the app's `config/embed.php` always does);
     * beam-embed never hardcodes the tower class (topology). Loss-free because every caller persists the
     * working row *before* snapshotting.
     */
    private function baseThread(Conversation $chat): Conversation
    {
        // TH-08 phase 5 (B2): the former `config('embed.base_model')` host seam is retired for beam-embed's
        // own {@see BaseThread} — the same `beam_threads` row under the base `thread` morph, carrying the full
        // working config off the sidecars (topology-clean: no tower reference).
        return Thread::query()->findOrFail($chat->getKey());
    }

    /**
     * Update an ALREADY-published embed's config in place — the launcher cosmetics, origin
     * allowlist, kill-switch and wallet — WITHOUT re-publishing (no `template_version` bump, no
     * instruction snapshot). This is the ticket-04 config slice: it never touches instructions
     * (that edit-and-re-publish path is gated on ticket 13). Only whitelisted keys are merged; any
     * `$launcher` passed replaces the stored cosmetics wholesale (already-published surfaces send
     * the full block). The live key's central `allowed_origins` is kept in sync when origins change.
     *
     * @param  list<string>|null  $allowedOrigins  null = leave origins unchanged.
     * @param  array<string, mixed>|null  $launcher  null = leave launcher unchanged; [] clears it.
     */
    public function updateConfig(
        Conversation $chat,
        ?array $allowedOrigins = null,
        ?float $walletCreditUsd = null,
        ?bool $enabled = null,
        ?array $launcher = null,
        bool $walletProvided = false,
    ): void {
        $policy = $chat->embed_policy ?? [];

        if ($enabled !== null) {
            $policy['enabled'] = $enabled;
        }

        if ($walletProvided) {
            if ($walletCreditUsd === null) {
                unset($policy['wallet_credit_usd']);
            } else {
                $policy['wallet_credit_usd'] = $walletCreditUsd;
            }
        }

        if ($allowedOrigins !== null) {
            if ($allowedOrigins === []) {
                unset($policy['allowed_origins']);
            } else {
                $policy['allowed_origins'] = array_values($allowedOrigins);
            }
        }

        if ($launcher !== null) {
            $cosmetic = Arr::only($launcher, self::LAUNCHER_COSMETIC_KEYS);
            if ($cosmetic === []) {
                unset($policy['launcher']);
            } else {
                $policy['launcher'] = $cosmetic;
            }
        }

        $chat->update(['embed_policy' => $policy]);

        // Keep the enforced central allowlist in sync (the live key is the source of truth for
        // origin checks before tenancy init).
        if ($allowedOrigins !== null) {
            EmbedKey::query()
                ->where('published_chat_id', $chat->id)
                ->whereNull('revoked_at')
                ->update(['allowed_origins' => $allowedOrigins === [] ? null : array_values($allowedOrigins)]);
        }
    }
}
