<?php

namespace Splicewire\Beam\Embed\Accounts;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Embed\Data\AuthUserEmbedData;
use Splicewire\Beam\Embed\Models\Embed;
use Splicewire\Beam\Embed\Models\EmbedKey;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Read\ReadContext;

/**
 * Embed's contribution to the `me` read projection — the support launcher's publishable key, attached
 * to a resource `laravel-beam-accounts` owns (particle-contribution-seam 16/18).
 *
 * The contributor declares; the owner names nothing. beam-accounts has never heard of an embed.
 *
 * ## Value arm only, and the record is not what it keys on
 *
 * No `includes` arm, because there is no relation to eager-load. The retired
 * `TowerAuthUserExtras::contribute(Authenticatable $user)` **ignored its own `$user` argument in both
 * branches**: this key is a property of the current TENANT's platform embed, not of the calling user.
 * Ambient tenancy context, not relation-reachable and not even user-reachable — which is why the
 * cheap arm has nothing to do here (ticket 16 §A5).
 *
 * ## One indexed lookup per read, and `me` is a singleton
 *
 * Two queries at most (the embed, then its live key), on a resource with exactly one row per request.
 * The per-row cost that makes a value arm expensive on a LIST does not arise.
 */
class AuthUserEmbedContributor
{
    /** The sub-projection key the slice lands under: `data.embed`. */
    public const AS = 'embed';

    /** The resource key contributed to — beam-accounts' `me`, a string because the owner boots later. */
    public const KEY = 'me';

    public function contribution(): ResourceContribution
    {
        return new ResourceContribution(
            key: self::KEY,
            as: self::AS,
            data: AuthUserEmbedData::class,
            value: fn (Model $record, ReadContext $ctx, array $filters): AuthUserEmbedData => new AuthUserEmbedData(
                platformEmbedPk: $this->platformEmbedPk(),
            ),
        );
    }

    /**
     * The live support-widget `pk_`: the current non-revoked self-deliver key for the tenant's
     * platform-visibility embed, read from the DB. Env is a pure fallback for a tenant that has not
     * re-provisioned onto the DB-plaintext key yet, and the only answer in central context, where
     * there is no tenant schema to look in.
     *
     * Lifted from `TowerAuthUserExtras::resolvePlatformEmbedPk()`, which lifted it from the retired
     * `AuthUserResource::resolveSupportEmbedPk()` — so it had survived two moves unread. Read on the
     * third: the branch is correct, and the only change is that it now lives in the package that owns
     * both models it names.
     */
    protected function platformEmbedPk(): ?string
    {
        if (! tenancy()->initialized) {
            return config('embed.support_pk');
        }

        $embed = Embed::query()
            ->where('visibility', 'platform')
            ->latest('created_at')
            ->first();

        if ($embed !== null) {
            $key = EmbedKey::currentSelfDeliver((string) $embed->getKey());

            if ($key?->key_plaintext) {
                return $key->key_plaintext;
            }
        }

        return config('embed.support_pk');
    }
}
