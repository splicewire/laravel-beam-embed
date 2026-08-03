<?php

namespace Splicewire\Beam\Embed\Types;

use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Contracts\CapabilityHandler;
use Splicewire\Beam\Embed\Types\Handlers\UnsupportedCapabilityHandler;

/**
 * The default embed TYPE on a bare beam site (recohere T30): remote-embed substrate with no host
 * corpus. It registers the generic broker and the deny-by-default handlers, so a bare beam site gets
 * the whole remote-embed machinery — spawn, publish, mint a scoped token, broker + audit a call — out
 * of the box, with brokered reads refusing (there is nothing to serve). A host embed type overrides
 * the handlers to reach a real corpus.
 */
class GenericEmbedType implements EmbedType
{
    public const Key = 'generic';

    public function key(): string
    {
        return self::Key;
    }

    /**
     * @return array<string, class-string<CapabilityHandler>>
     */
    public function capabilityHandlers(): array
    {
        return [
            Capability::Resolve->value => UnsupportedCapabilityHandler::class,
            Capability::ReadScoped->value => UnsupportedCapabilityHandler::class,
            Capability::RequestSave->value => UnsupportedCapabilityHandler::class,
        ];
    }

    public function brokerClass(): string
    {
        return CapabilityBroker::class;
    }
}
