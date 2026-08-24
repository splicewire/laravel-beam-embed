<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * Embed's slice of the `me` read projection — the publishable key the in-app support launcher boots
 * with (particle-contribution-seam 18).
 *
 * Lands nested under `embed` (`data.embed.platformEmbedPk`), namespaced by the package that owns the
 * concept rather than competing for a flat top-level name.
 *
 * ⚠️ Like commerce's sibling slice, this reached the auth projection through TOWER until ticket 18 —
 * not because an embed key is a tower concept, but because the seam it rode was a single container
 * binding and two packages cannot both hold one. beam-embed requires beam-tenancy which requires
 * beam-accounts, so the reach-down was legal all along (ticket 16 §A1).
 */
#[TypeScript]
class AuthUserEmbedData extends Data
{
    public function __construct(
        /**
         * The live `pk_` for this tenant's platform-visibility embed, else the `embed.support_pk` env
         * fallback for a tenant that has not re-provisioned onto the DB-plaintext key yet.
         *
         * ⚠️ Named `platformEmbedPk`, not `supportEmbedPk`. The server renamed it on 2026-08-05 because
         * the lookup is literally `where('visibility', 'platform')` and "support" was vestigial — but
         * the SPA kept destructuring the old name off `/me`, so the dogfood launcher has been dark ever
         * since. Ticket 18 moves the field and fixes the consumer in the same edit, which is the point:
         * a field routed through a hand-written host DTO drifted from its consumer *silently*.
         */
        public ?string $platformEmbedPk = null,
    ) {}
}
