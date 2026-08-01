<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Models\EmbedSpendReservation;
use Splicewire\Beam\Commerce\TokenTransaction;
use Rushing\Commerce\Contracts\UsageMeter;

/**
 * The debit side of an Embed prepaid wallet (DIE-06): how much of a published chat's
 * prepaid balance has been consumed. Read from the platform's authoritative usage
 * ledger — the TokenTransactions attributed to the published chat via the Concierge's
 * `attribution_target` (= embed_key id, DIE-02/05). The commerce package owns the
 * credit (money-in) side; it never keeps a usage ledger of its own.
 *
 * DIE-12: consumed spend also includes any active (non-expired) in-flight
 * {@see EmbedSpendReservation} holds, so a message that has passed the gate but not yet
 * billed already counts against the wallet — concurrent public requests can't all read
 * the same stale headroom before any of them debits.
 */
class EmbedUsageMeter implements UsageMeter
{
    public function debitedFor(string $partyId, string $unit): float
    {
        $debited = (float) TokenTransaction::query()
            ->where('metadata->attribution_target', $partyId)
            ->sum('cost_usd');

        $reserved = (float) EmbedSpendReservation::query()
            ->where('embed_key_id', $partyId)
            ->where('expires_at', '>', now())
            ->sum('estimated_usd');

        return $debited + $reserved;
    }
}
