<?php

namespace Splicewire\Beam\Embed\Models;

use Splicewire\Beam\Embed\EmbedUsageMeter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An in-flight hold on an Embed's prepaid wallet (DIE-12). Created under an advisory
 * lock at the budget gate and counted by {@see EmbedUsageMeter} while
 * active, so concurrent public requests against one `pk_` see each other's pending
 * spend instead of all passing a stale headroom read. Settled (deleted) when its
 * stream completes; auto-expires (`expires_at`) if the stream never settles.
 *
 * Tenant-local runtime state — not Syncable.
 */
class EmbedSpendReservation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'estimated_usd' => 'float',
        'expires_at' => 'immutable_datetime',
    ];
}
