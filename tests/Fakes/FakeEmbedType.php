<?php

namespace Splicewire\Beam\Embed\Tests\Fakes;

use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Types\EmbedType;

/**
 * A registerable embed type with caller-supplied handlers — the "a second type plugs in here" seam the
 * registry exists for, exercised without dragging in a host package.
 */
class FakeEmbedType implements EmbedType
{
    /**
     * @param  array<string, mixed>  $handlers  keyed by Capability::value; instances or class-strings
     */
    public function __construct(
        private string $key,
        private array $handlers = [],
        private ?string $brokerClass = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function capabilityHandlers(): array
    {
        return $this->handlers;
    }

    public function brokerClass(): string
    {
        return $this->brokerClass ?? CapabilityBroker::class;
    }
}
