<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per brokered-capability invocation (RCP-05). The host writes it so there is a
 * durable record of exactly what an isolated remote guest asked the host to run on its
 * behalf — allowed or refused, under which trust tier, bound to which embed session.
 * Lives on the tenant connection alongside the published chat it belongs to.
 */
class EmbedCapabilityAudit extends Model
{
    use HasUuids;

    protected $table = 'embed_capability_audits';

    protected $fillable = [
        'embed_key_id',
        'session_id',
        'capability',
        'tier',
        'allowed',
        'refusal_reason',
        'request',
    ];

    protected $casts = [
        'allowed' => 'boolean',
        'request' => 'array',
    ];
}
