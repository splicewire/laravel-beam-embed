<?php

namespace Splicewire\Beam\Embed\Capability;

/**
 * The two trust tiers a remote component runs under (RCP-05) — same vocabulary either
 * way, differing only in substrate + which capabilities the mint will grant:
 *
 *  - FirstParty ("self"): a component the platform authored/trusts. It may run on the
 *    worker-only substrate and receives the richer capability set (resolve + read +
 *    save). The trust boundary is still real, but the grant is wide.
 *  - UntrustedPublisher: an arbitrary remote publisher (e.g. a thingsontv block). It
 *    runs isolated (QuickJS + separate origin, RCP-01) and gets the MINIMAL grant —
 *    read-only brokered capabilities, never a save.
 *
 * The tier is stamped on the minted token and re-checked at the broker, so a token
 * cannot be replayed to reach a capability its tier was never granted.
 */
enum TrustTier: string
{
    case FirstParty = 'first_party';
    case UntrustedPublisher = 'untrusted_publisher';

    /**
     * The default set of capabilities this tier is allowed to be granted. The mint may
     * narrow this further, but never widen it — a capability outside the tier's set is
     * refused at mint time and, defensively, again at the broker.
     *
     * @return list<Capability>
     */
    public function defaultCapabilities(): array
    {
        return match ($this) {
            self::FirstParty => [Capability::Resolve, Capability::ReadScoped, Capability::RequestSave],
            self::UntrustedPublisher => [Capability::Resolve, Capability::ReadScoped],
        };
    }

    public function permits(Capability $capability): bool
    {
        return in_array($capability, $this->defaultCapabilities(), true);
    }
}
