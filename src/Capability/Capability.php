<?php

namespace Splicewire\Beam\Embed\Capability;

/**
 * The small, explicit set of brokered capabilities an isolated remote guest may invoke
 * back through the host (RCP-05). Every one is host-mediated + audited — the guest
 * never holds a credential or makes a raw fetch. A capability not in the guest's grant
 * (or a payload outside the token's scope) is refused at the broker.
 *
 *  - Resolve     — dereference a `$ref` (an `@id`) through the shipped dereference
 *                  primitive (the flagship, fully wired here).
 *  - ReadScoped  — read scoped data bounded by the token's frozen scope (seam + one
 *                  concrete proof; the refusal path is real).
 *  - RequestSave — request a server-side save (first-party only; seam + refusal proof).
 */
enum Capability: string
{
    case Resolve = 'resolve';
    case ReadScoped = 'read_scoped';
    case RequestSave = 'request_save';

    public static function tryFromName(?string $name): ?self
    {
        return $name === null ? null : self::tryFrom($name);
    }
}
