<?php

namespace Splicewire\Beam\Embed\Types;

use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Contracts\CapabilityHandler;

/**
 * One pluggable embed TYPE (recohere T30). "Remote embed" is the beam-tier capability; a type is one
 * concrete flavour of it — tower's `concierge` (a grounded chat over a scoped corpus) is ONE type, a
 * future satellite `chat-widget` would be another, and neither is privileged over the other. The
 * user's framing: "concierge is just one type of embed; the feature is the generalized ability to
 * remote embed."
 *
 * A type declares the seams the generic remote-embed machinery resolves through it:
 *
 *  - {@see capabilityHandlers()} — the per-capability server-side executors the broker dispatches to
 *    (the ONLY place a type's host/knowledge coupling lands; the broker itself stays generic);
 *  - {@see brokerClass()} — the broker implementation, defaulting to the generic
 *    {@see CapabilityBroker}; a type overrides it only if it needs a
 *    bespoke envelope (it rarely should — the envelope is the invariant part).
 *
 * Scope/audit/pivot-freeze remain container-bound PORTS (ScopeResolver, CapabilityAuditRecorder,
 * KnowledgePivotFreezer) rather than per-type methods, because they are cross-cutting to the whole
 * embed subsystem, not per-flavour. A type composes them; it does not redeclare them.
 */
interface EmbedType
{
    /**
     * The stable key that selects this type (e.g. `concierge`, `chat-widget`). Matches the `embed_type`
     * an embed is published under; the registry keys on it.
     */
    public function key(): string;

    /**
     * The capability handlers this type provides, keyed by the {@see Capability} they service. A
     * capability with no handler is unsupported for this type and the broker refuses it. Values are
     * container-resolvable class-strings (or handler instances).
     *
     * @return array<string, class-string<CapabilityHandler>|CapabilityHandler>
     *                                                                          keyed by {@see Capability::value}
     */
    public function capabilityHandlers(): array;

    /**
     * The broker class the active type is brokered through. Defaults to the generic broker; a type
     * overrides only for a bespoke envelope.
     *
     * @return class-string<CapabilityBroker>
     */
    public function brokerClass(): string;
}
