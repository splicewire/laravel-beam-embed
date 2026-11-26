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
        private string $label = 'Test embed',
        private array $schema = ['type' => 'object', 'properties' => []],
        private ?string $scriptUrl = 'https://example.test/embed.js',
        private array $sourceKinds = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function configSchema(): array
    {
        return $this->schema;
    }

    public function clientScriptUrl(): ?string
    {
        return $this->scriptUrl;
    }

    public function capabilityHandlers(): array
    {
        return $this->handlers;
    }

    public function brokerClass(): string
    {
        return $this->brokerClass ?? CapabilityBroker::class;
    }

    public function sourceKinds(): array
    {
        return $this->sourceKinds;
    }
}
