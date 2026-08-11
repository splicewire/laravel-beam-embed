<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Threads\Concerns\ConversationParticle;
use Splicewire\Beam\Threads\Concerns\EmbedParticle;
use Splicewire\Beam\Threads\Concerns\HasSidecarColumns;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Threads\Models\Participant;
use Splicewire\Beam\Threads\Models\Thread;

/**
 * The BASE-morph conversation view of an embed row (TH-07/TH-08 phase 5, B2) — beam-embed's OWN replacement
 * for the retired `config('embed.base_model')` host seam.
 *
 * The seam existed so beam-embed could re-resolve an {@see Embed} (whose `getMorphClass()` is the `embed`
 * permission alias, ADR-0118) as the BASE `thread` morph for version writes — the spawner reads versions
 * filed under `thread`, never the empty `embed` lineage. It pointed at the tower Thread only to (a) get the
 * `thread` morph and (b) carry the full working config on the version snapshot.
 *
 * Both are now satisfied WITHOUT referencing tower (topology): this model is the SAME `beam_threads` row
 * (shared id) and composes the SAME sidecar traits as {@see Embed} — {@see ConversationParticle} (whose
 * `toVersionSnapshot()` freezes the interactive-AI config) + {@see EmbedParticle} + {@see HasSidecarColumns}
 * with the same {@see sidecarColumnMap()} — so it reads model / instructions / agent / tools off the
 * `thread_headers` sidecar ITSELF. It is beam-embed-owned, so no beam-embed → tower edge is introduced.
 *
 * Unlike {@see Embed}, it declares NO `embed`-alias `getMorphClass()` (it IS the base `thread` morph) and NO
 * template-listing global scope (it is the base view of ONE row, resolved by id, not a listable set).
 *
 * House style: NO `final`, NO `readonly`, NO `declare(strict_types=1)`.
 */
class BaseThread extends Model implements Conversation, Versionable
{
    use ConversationParticle;
    use EmbedParticle;
    use HasSidecarColumns;

    /**
     * The SAME physical particle table as the Embed / tower Thread header (shared id). The table name is the
     * config seam — PREFIXED like every other beam particle (routed through {@see Beam::table()}), never
     * renamed unprefixed.
     */
    public function getTable(): string
    {
        return config('beam.threads.tables.threads', Beam::table('threads'));
    }

    /**
     * The sidecar column map — identical to {@see Embed::sidecarColumnMap()} so the version snapshot carries
     * the interactive-AI config off the `thread_headers` sidecar and the embed/publish config off
     * `embed_sessions`.
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

    /** The interactive-AI config sidecar (host-bound via `config('embed.header_model')`, topology-safe). */
    public function header(): HasOne
    {
        return $this->hasOne(config('embed.header_model'), 'thread_id');
    }

    /** The embed/publish config sidecar (beam-embed's own EmbedSession). */
    public function embedSession(): HasOne
    {
        return $this->hasOne(EmbedSession::class, 'thread_id');
    }

    /** The thread's participants — the beam-threads membership rows. */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'thread_id');
    }

    /**
     * The BASE morph — `thread`, NOT the `embed` permission alias. Versions are filed under this so the
     * spawner reads them back (mirrors the substrate {@see Thread}).
     */
    public function getMorphClass(): string
    {
        return 'thread';
    }
}
