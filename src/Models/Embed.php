<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\Concerns\ConversationParticle;
use Splicewire\Beam\Threads\Concerns\EmbedParticle;
use Splicewire\Beam\Threads\Concerns\HasSidecarColumns;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Threads\Enums\ThreadKind;
use Splicewire\Beam\Threads\Models\Participant;

/**
 * A published embed — a policied aggregate over the `threads` table (ticket 03, ADR-0118 morph-alias).
 *
 * It is the SAME row as a base conversation of `kind = embed_template`, but re-typed as its own model so
 * it mints a dedicated **`embed.*`** permission-token family (the base morph class is `thread`, so
 * `embed.rotate-key` is only reachable via this distinct aliased model). The `embed` alias is a token
 * vehicle only — it never lands in a stored `*_type` column (the row's real polymorphic children were
 * written as `thread`), so it is deliberately absent from the app morphMap's inverse resolution and set
 * only via {@see getMorphClass()} here.
 *
 * Recohere T02 / TH-07: Embed carries the generic conversation machinery via the {@see ConversationParticle}
 * trait (beam-threads — the successor to the deleted beam-core `ChatBase`), not the tower Thread, so it
 * gets casts / fillable / HasVisibility / HasUserId / `embed_policy` handling but NOT the knowledge
 * relations (fragments/contextScopes live UP on the tower Thread; the embed subsystem reaches them through
 * the T02 ports). It narrows to templates via a global scope so `Embed::query()` is always the listable
 * "published embeds" set. Key material stays CENTRAL: hydrate {@see EmbedKey} per row via {@see keys()}
 * / {@see liveKey()}.
 */
class Embed extends Model implements Conversation, Versionable
{
    use ConversationParticle;
    use EmbedParticle;
    use HasSidecarColumns;

    /**
     * The SAME physical table as the base conversation — an Embed IS a conversation particle row (of kind
     * embed_template), not a table of its own. TH-07 header migration (phase 4): the header moved onto the
     * particle; the interactive-AI and embed/publish columns are surfaced from sidecars. The table name is
     * the config seam — PREFIXED like every other beam particle (routed through {@see Beam::table()}),
     * never renamed unprefixed.
     */
    public function getTable(): string
    {
        return config('beam.threads.tables.threads', Beam::table('threads'));
    }

    /**
     * The sidecar column map (TH-07 header migration, phase 4). The interactive-AI config resolves off the
     * host-bound `header` model (`config('embed.header_model')`, tower binds ThreadHeader — beam-embed never
     * hardcodes the tower class, topology); the embed/publish config off beam-embed's own EmbedSession.
     *
     * @return array<string, array<int, string>>
     */
    public function sidecarColumnMap(): array
    {
        return [
            'header' => [
                'model',
                'model_params',
                'agent_id',
                'instructions_provider',
                'instructions_text',
                'instructions_schemas',
                'tools',
                'max_tool_steps',
            ],
            'embedSession' => [
                'visitor_id',
                'published_from_id',
                'snapshot_config',
                'template_version',
                'embed_policy',
                'retention_days',
                'session_status',
            ],
        ];
    }

    /**
     * The interactive-AI config sidecar, resolved through the host seam `config('embed.header_model')` so
     * beam-embed never references the tower ThreadHeader class (topology). Left unset on a bare beam site,
     * the relation resolves to an empty association.
     */
    public function header(): HasOne
    {
        return $this->hasOne(config('embed.header_model'), 'thread_id');
    }

    /** The embed/publish config sidecar (beam-embed's own EmbedSession — no topology concern). */
    public function embedSession(): HasOne
    {
        return $this->hasOne(EmbedSession::class, 'thread_id');
    }

    /** The thread's participants — the beam-threads membership rows (owner resolution). */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'thread_id');
    }

    /** The morph alias that mints the `embed.*` token family (ADR-0118). */
    public function getMorphClass(): string
    {
        return 'embed';
    }

    protected static function booted(): void
    {
        // The listable set: only published templates. Sessions (`embed_session`) and ordinary
        // chats (`interactive`) are never Embeds. Sits on top of any base global scopes.
        static::addGlobalScope('embedTemplate', function (Builder $query) {
            $query->where($query->getModel()->getTable().'.kind', ThreadKind::EmbedTemplate->value);
        });
    }

    /**
     * The central {@see EmbedKey}(s) pointing at this published chat — a cross-connection lookup
     * (the key pointer is central; the embed is tenant-local), keyed by `published_chat_id`.
     *
     * @return Collection<int, EmbedKey>
     */
    public function keys()
    {
        return EmbedKey::query()
            ->where('published_chat_id', $this->getKey())
            ->latest('created_at')
            ->get();
    }

    /**
     * A base-conversation view of this row for VERSIONING (ticket 13). Versioning keys off
     * `getMorphClass()`; this model's is the `embed` permission alias (ADR-0118), but versions are
     * filed under the base `thread` morph — which is how the spawner reads them. Any version READ for an
     * embed (its HEAD / changes-pending diff) must go through this, never `$this`, or it would query the
     * empty `embed` lineage. Carries only the key + HEAD pin. (A version WRITE additionally needs the
     * full working columns, so publishing re-resolves a full base model from the persisted row.)
     *
     * TH-08 phase 5 (B2): the base class is beam-embed's own {@see BaseThread} (same beam_threads row, base
     * `thread` morph, config off the sidecars) — the `config('embed.base_model')` host seam is retired.
     */
    public function versionable(): Conversation
    {
        // TH-08 phase 5 (B2): beam-embed's own base-morph {@see BaseThread} (the same beam_threads row under
        // the `thread` morph), replacing the retired `config('embed.base_model')` host seam.
        return (new Thread)->forceFill([
            $this->getKeyName() => $this->getKey(),
            'head_version' => $this->head_version,
        ]);
    }

    /**
     * A DB-fresh, fully-hydrated base-conversation view for the shared versioning seam
     * (`Route::recordVersions('embeds', …, via: 'versionableRecord')`, ADR-0127). Unlike the lean
     * {@see Versionable()} (id + HEAD pin only), this reloads the full working row so a version WRITE
     * (save / restore) freezes the real conversation config, and files it under the base morph — never
     * the `embed` permission alias, which the spawner would read as an empty lineage.
     */
    public function versionableRecord(): Conversation
    {
        // TH-08 phase 5 (B2): beam-embed's own base-morph {@see BaseThread}, replacing the retired
        // `config('embed.base_model')` host seam. Files versions under the base `thread` morph (never `embed`).
        return Thread::query()->findOrFail($this->getKey());
    }

    /** The current live (non-revoked) key for this embed — the one serving traffic. Newest wins. */
    public function liveKey(): ?EmbedKey
    {
        return EmbedKey::query()
            ->where('published_chat_id', $this->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }
}
