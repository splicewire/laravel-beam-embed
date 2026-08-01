<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A drop-in Visitor (DIE-04 / ADR-0062): the lightweight, tenant-schema principal
 * that owns an `embed_session` Thread. It is NEVER a User — no FK to `users`. A
 * returning visitor is recognized by a short-lived session token (hashed);
 * ip/ua are stored hashed only. Token issuance is gated in issue 10.
 */
class Visitor extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_token_hash',
        'ip_hash',
        'ua_hash',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
