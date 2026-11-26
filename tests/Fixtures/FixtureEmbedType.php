<?php

namespace Splicewire\Beam\Embed\Tests\Fixtures;

use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Types\EmbedType;

class FixtureEmbedType implements EmbedType
{
    public function key(): string
    {
        return 'fixture';
    }

    public function label(): string
    {
        return 'Fixture embed';
    }

    public function sourceKinds(): array
    {
        return ['or35-probe-source'];
    }

    public function configSchema(): array
    {
        return ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
    }

    public function clientScriptUrl(): ?string
    {
        return 'https://example.test/fixture.js';
    }

    public function capabilityHandlers(): array
    {
        return [];
    }

    public function brokerClass(): string
    {
        return CapabilityBroker::class;
    }
}
