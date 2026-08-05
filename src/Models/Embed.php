<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Contracts\Conversation;
use Splicewire\Beam\Threads\Concerns\ConversationParticle;
use Splicewire\Beam\Threads\Concerns\EmbedParticle;
use Splicewire\Beam\Threads\Enums\ThreadKind;

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

    /**
     * The SAME physical table as the base conversation — an Embed IS a `threads` row (of kind
     * embed_template), not a table of its own.
     */
    protected $table = 'threads';

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
     * The base class is the host's `config('embed.base_model')` (tower binds `Splicewire\Tower\Models\Thread`).
     * TH-07 deleted the beam-core `ChatBase` fallback — the host MUST bind a base conversation model
     * (the app's `config/embed.php` always does); beam-embed never hardcodes the tower class (topology).
     */
    public function versionable(): Conversation
    {
        /** @var class-string<Model&Conversation> $base */
        $base = config('embed.base_model');

        return (new $base)->forceFill([
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
        /** @var class-string<Model&Conversation> $base */
        $base = config('embed.base_model');

        return $base::query()->findOrFail($this->getKey());
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
