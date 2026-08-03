<?php

namespace Splicewire\Beam\Embed\Policies;

use Splicewire\Beam\Embed\Models\Embed;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for managing published embeds (ticket 03, ADR-0118 morph-alias + ticket 14 ACL).
 *
 * Rides beam-core's generic {@see BaseModelPolicy} cascade over the `embed.*` token family (minted
 * because {@see Embed::getMorphClass()} returns `embed`). Extends the cascade base
 * ({@see \Rushing\PermissionCascade\Policies\BaseModelPolicy}) DIRECTLY rather than the tower host
 * base — the host base is an unmodified subclass of the same cascade base, so this policy stays
 * tower-free while getting byte-identical behavior (recohere Lane A cluster 7). The listable set is
 * {@see BaseModelPolicy::scopeForUser()} on the `.shared.` directory-ACL rung: the steward always
 * manages (non-deniable), any tenant member may *view* a `visibility=platform` embed (the support
 * embed), and *manage* of a platform embed is gated to the `platform.manage` token.
 *
 * Beyond the base `view/create/update/delete`, key operations are MORE SENSITIVE than editing an
 * embed's config, so they get their own graduated abilities on the aggregate — `embed.rotate-key`
 * and `embed.revoke-key` — each resolving through the same cascade (they are treated as `manage`
 * at the `.shared.` tier, so steward + `platform.manage` reach them, and a distinct token can be
 * granted for them independently of plain `embed.update`).
 */
class EmbedPolicy extends BaseModelPolicy
{
    public static $defaultModelClass = Embed::class;

    /** May the actor rotate this embed's key (issue-new + revoke-old)? */
    public function rotateKey(Authenticatable $user, Model $instance): bool
    {
        return $this->canCascade($user, $instance, 'rotate-key');
    }

    /** May the actor revoke this embed's key (take it dark)? */
    public function revokeKey(Authenticatable $user, Model $instance): bool
    {
        return $this->canCascade($user, $instance, 'revoke-key');
    }
}
