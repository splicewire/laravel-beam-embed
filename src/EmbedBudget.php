<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Commerce\Budget\BudgetLine;
use Splicewire\Beam\Commerce\Budget\EffectiveBudget;
use Splicewire\Beam\Commerce\Budget\SpendContext;
use Splicewire\Beam\Commerce\Budget\SpendingCap;
use Splicewire\Beam\Commerce\Budget\StopKind;
use App\Data\BudgetVerdictData;
use Splicewire\Beam\Embed\Models\EmbedSpendReservation;
use Splicewire\Beam\Models\ChatBase;
use Illuminate\Support\Facades\DB;
use Rushing\Commerce\Credit;

/**
 * The public Embed path's spend authority (DIE-06 / ADR-0062 decision 6): a `pk_` is
 * public and will leak, so the public path must hard-stop on its own prepaid slice,
 * isolating blast radius from the tenant's generation budget.
 *
 * Composes, through {@see EffectiveBudget}, the outer tenant/broker postpaid ceiling
 * with a per-Published-Chat prepaid wallet ({@see Credit}, debited by the Concierge's
 * embed-attributed spend). The wallet uses hard-stop semantics
 * ({@see StopKind::PrepaidExhausted}), so at zero the Embed denies (escalation-only,
 * issue 09) while the tenant's own generation is untouched — and the tenant ceiling
 * still binds when it is the tighter of the two. A per-Embed enabled flag is a
 * kill-switch honored here.
 */
class EmbedBudget
{
    public function resolve(ChatBase $published, ?string $embedKeyId): BudgetVerdictData
    {
        $policy = $published->embed_policy ?? [];

        if (! ($policy['enabled'] ?? true)) {
            return $this->disabled();
        }

        $lines = [];

        if (tenant() !== null) {
            $party = (new SpendContext(tenant()))->billingParty();
            $lines[] = new BudgetLine('postpaid_cap', StopKind::CapReached, new SpendingCap($party));
        }

        $walletCredit = $this->walletCredit($policy);
        if ($walletCredit !== null && $embedKeyId !== null) {
            $wallet = new Credit(
                partyId: $embedKeyId,
                unit: 'usd',
                credited: $walletCredit,
                meter: new EmbedUsageMeter,
                warnFraction: 0.8,
            );
            $lines[] = new BudgetLine('prepaid_wallet', StopKind::PrepaidExhausted, $wallet);
        }

        if ($lines === []) {
            return new BudgetVerdictData(
                allowed: true, uncapped: true, warning: false,
                spentUsd: 0.0, capUsd: null, remainingUsd: null, fractionUsed: null, offer: null,
            );
        }

        return app(EffectiveBudget::class)->resolve($lines);
    }

    /**
     * The concurrency-safe gate for the public path (DIE-12). Resolves the verdict and,
     * when the prepaid wallet allows, places a short-lived spend reservation — the whole
     * gate+reserve runs under a per-Embed Postgres advisory lock, so a flood of
     * concurrent `pk_` requests is serialized: each sees the prior reservations and the
     * wallet can't be cumulatively overspent between the read and the debit.
     *
     * With no prepaid wallet there is no drained balance to protect (the postpaid cap is
     * an outer ceiling), so this is a plain {@see resolve()}.
     */
    public function reserve(ChatBase $published, ?string $embedKeyId, ?string $sessionId = null): BudgetVerdictData
    {
        $policy = $published->embed_policy ?? [];
        $hasWallet = $this->walletCredit($policy) !== null && $embedKeyId !== null;

        if (! $hasWallet) {
            return $this->resolve($published, $embedKeyId);
        }

        return DB::transaction(function () use ($published, $embedKeyId, $sessionId) {
            // Serialize the gate+reserve critical section per Embed key. The lock is
            // held for the (brief) transaction only, never across the stream.
            DB::select('select pg_advisory_xact_lock(?)', [crc32((string) $embedKeyId)]);

            $verdict = $this->resolve($published, $embedKeyId);

            if ($verdict->allowed) {
                EmbedSpendReservation::create([
                    'embed_key_id' => $embedKeyId,
                    'session_id' => $sessionId,
                    'estimated_usd' => (float) config('embed.reservation.estimate_usd', 0.05),
                    'expires_at' => now()->addSeconds((int) config('embed.reservation.ttl_seconds', 120)),
                ]);
            }

            return $verdict;
        });
    }

    /**
     * Release a session's in-flight reservations once its stream has settled (the actual
     * debit is now the source of truth). Orphaned reservations from a crashed stream are
     * cleaned up by their `expires_at` instead.
     */
    public function settle(?string $sessionId): void
    {
        if ($sessionId === null) {
            return;
        }

        EmbedSpendReservation::query()->where('session_id', $sessionId)->delete();
    }

    /**
     * The prepaid slice that binds this Embed (DIE-13). An explicit
     * `wallet_credit_usd` wins; otherwise the platform default
     * (`embed.default_wallet_credit_usd`) applies, so an Embed published without a
     * wallet is still blast-radius-isolated by default rather than silently spending
     * against the tenant's whole generation budget. A null default opts the platform
     * out (tenant/broker ceiling only).
     *
     * @param  array<string, mixed>  $policy
     */
    private function walletCredit(array $policy): ?float
    {
        $credit = $policy['wallet_credit_usd'] ?? config('embed.default_wallet_credit_usd');

        return $credit === null ? null : (float) $credit;
    }

    private function disabled(): BudgetVerdictData
    {
        return new BudgetVerdictData(
            allowed: false, uncapped: false, warning: false,
            spentUsd: 0.0, capUsd: 0.0, remainingUsd: 0.0, fractionUsed: 1.0, offer: null,
            bindingSourceId: 'embed_disabled', stopKind: StopKind::PrepaidExhausted->value,
        );
    }
}
