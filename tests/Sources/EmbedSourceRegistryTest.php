<?php

namespace Splicewire\Beam\Embed\Tests\Sources;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Embed\Contracts\EmbedSourceResolver;
use Splicewire\Beam\Embed\Sources\EmbedSourceRegistry;
use Splicewire\Beam\Embed\Sources\EmbedSourceResolution;
use Splicewire\Beam\Embed\Tests\TestCase;

class EmbedSourceRegistryTest extends TestCase
{
    public function test_source_resolution_is_keyed_by_the_models_morph_alias(): void
    {
        $source = new class extends Model
        {
            public function getMorphClass(): string
            {
                return 'thread';
            }
        };

        $registry = new EmbedSourceRegistry;
        $registry->register('thread', new class implements EmbedSourceResolver
        {
            public function resolve(Model $source): EmbedSourceResolution
            {
                return new EmbedSourceResolution('A conversation', 'concierge');
            }
        });

        $resolved = $registry->resolve($source);

        $this->assertSame('A conversation', $resolved->displayName);
        $this->assertSame('concierge', $resolved->fixedType);
    }

    public function test_unregistered_source_alias_is_refused(): void
    {
        $source = new class extends Model
        {
            public function getMorphClass(): string
            {
                return 'schema';
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        (new EmbedSourceRegistry)->resolve($source);
    }
}
