<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The central Embed Key pointer (DIE-03 / ADR-0062 decision 3): a hashed `pk_` that
 * resolves a drop-in request to its tenant + Published Chat before tenancy
 * initializes. Pointer only — the config IS the Published Chat (tenant-schema).
 *
 * The `pk_` authorizes spawn-a-session-from-this-published-chat only; it never
 * grants reading the Published Chat or its sibling sessions.
 */
class EmbedKey extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'embed_keys';

    protected $fillable = [
        'key_hash',
        'last_four',
        'self_deliver',
        'key_plaintext',
        'tenant_id',
        'published_chat_id',
        'allowed_origins',
        'revoked_at',
    ];

    protected $casts = [
        'allowed_origins' => 'array',
        'self_deliver' => 'boolean',
        'revoked_at' => 'immutable_datetime',
    ];

    /**
     * Mint a new key. Returns [model, plaintext].
     *
     * For a third-party embed (`$selfDeliver = false`, the default) the plaintext is shown ONCE
     * and never stored — only its sha256 hash is persisted, exactly as before. For a *first-party*
     * embed (`$selfDeliver = true`, the in-app support widget) the plaintext is ALSO stored on the
     * row so the app can read it back at `/me` request time — the pk is a public bearer token
     * already in every browser, so this leaks nothing new (ticket 03 / 04, DB-dump tradeoff accepted).
     *
     * @param  list<string>  $allowedOrigins
     * @return array{0: self, 1: string}
     */
    public static function issue(string $tenantId, string $publishedChatId, array $allowedOrigins = [], bool $selfDeliver = false): array
    {
        $plaintext = 'pk_'.Str::random(40);

        $key = static::create([
            'key_hash' => static::hash($plaintext),
            'last_four' => substr($plaintext, -4),
            'self_deliver' => $selfDeliver,
            // Plaintext stored ONLY for first-party self-deliver keys (null for third-party).
            'key_plaintext' => $selfDeliver ? $plaintext : null,
            'tenant_id' => $tenantId,
            'published_chat_id' => $publishedChatId,
            'allowed_origins' => $allowedOrigins,
        ]);

        return [$key, $plaintext];
    }

    /**
     * Rotate this key: issue a fresh key for the same embed (inheriting origins + the self-deliver
     * posture) and IMMEDIATELY revoke this one — no grace window (ticket 03). Only an un-refreshed
     * tab spawning a *new* session gets a transient 401 (cured by reload); open sessions are
     * unaffected (revoke blocks new spawns only).
     *
     * @return array{0: self, 1: string} the new key + its plaintext
     */
    public function rotate(): array
    {
        [$new, $plaintext] = static::issue(
            $this->tenant_id,
            $this->published_chat_id,
            $this->allowed_origins ?? [],
            (bool) $this->self_deliver,
        );

        $this->revoke();

        return [$new, $plaintext];
    }

    /** Soft-revoke this key: block new spawns without touching open sessions. Idempotent. */
    public function revoke(): void
    {
        if (! $this->isRevoked()) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * The current live (non-revoked) self-deliver key for a published chat — the one the app
     * re-serves to `/me`. Newest wins if more than one somehow exists.
     */
    public static function currentSelfDeliver(string $publishedChatId): ?self
    {
        return static::query()
            ->where('published_chat_id', $publishedChatId)
            ->where('self_deliver', true)
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    /** Look a key up by its plaintext — including revoked ones, so the caller can 401 vs 404. */
    public static function findByPlaintext(string $plaintext): ?self
    {
        return static::query()->where('key_hash', static::hash($plaintext))->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * True when the request origin is permitted. An empty/absent allowlist is open
     * (any origin); a populated allowlist admits an entry by exact match OR by a
     * single-label subdomain wildcard — `https://*.app.splicewire.test` lets ONE
     * first-party key serve every tenant subdomain (the in-app support widget).
     */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = $this->allowed_origins ?? [];

        if ($allowed === []) {
            return true;
        }

        if ($origin === null) {
            return false;
        }

        $candidate = $this->normalizeOrigin($origin);

        foreach ($allowed as $pattern) {
            if ($this->originMatches($candidate, $this->normalizeOrigin($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a normalized candidate origin against one allowlist entry: exact, or a
     * single-label subdomain wildcard. `https://*.app.splicewire.test` admits any
     * first-party tenant host (`https://foodsafety.app.splicewire.test`) but NOT the
     * bare apex and NOT a deeper multi-label host — the `*` stands for exactly one label.
     */
    private function originMatches(string $candidate, string $pattern): bool
    {
        if ($candidate === $pattern) {
            return true;
        }

        if (! str_contains($pattern, '*')) {
            return false;
        }

        $regex = '#^'.str_replace('\*', '[a-z0-9-]+', preg_quote($pattern, '#')).'$#i';

        return preg_match($regex, $candidate) === 1;
    }

    private function normalizeOrigin(string $origin): string
    {
        // Compare scheme://host[:port]; drop any path (a Referer carries one).
        $parts = parse_url(rtrim($origin, '/'));
        if (! isset($parts['host'])) {
            return rtrim($origin, '/');
        }

        $normalized = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        return $normalized;
    }
}
