<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `embed_sessions` sidecar (TH-07 header migration, phase 3) — the EMBED/PUBLISH facet of a conversation,
 * split off the legacy `threads` row so the tower Thread header can move onto the generic `beam_threads`
 * particle. One row per embed template/session, keyed by `thread_id` (PK) = the particle id (the
 * companion-by-id pattern).
 *
 * The consuming conversation model (tower Thread / beam-embed Embed) surfaces these fields back onto itself
 * via a 1:1 relation + accessors (phase 4), so callers keep reading `$thread->snapshot_config`,
 * `$thread->embed_policy`, `$thread->published_from_id`, etc. unchanged.
 *
 * House style: NO `final`, NO `readonly`, NO `declare(strict_types=1)`.
 */
class EmbedSession extends Model
{
    protected $table = 'embed_sessions';

    protected $primaryKey = 'thread_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'thread_id',
        'visitor_id',
        'published_from_id',
        'snapshot_config',
        'template_version',
        'embed_policy',
        'retention_days',
        'session_status',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_config' => 'array',
            'embed_policy' => 'array',
            'template_version' => 'integer',
            'retention_days' => 'integer',
        ];
    }

    /** The visitor who owns this session (nullable on a template). */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }
}
