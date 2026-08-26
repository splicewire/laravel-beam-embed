<?php

namespace Splicewire\Beam\Embed\Tests\Types;

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Embed\Tests\Fakes\FakeEmbedType;
use Splicewire\Beam\Embed\Tests\TestCase;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Embed\Types\GenericEmbedType;

/**
 * The kernel half of {@see EmbedTypeRegistryTest} — registry-kernel ticket 38, archetype a / PickOne.
 * That file asserts the port's own behaviour is unchanged by the migration; this one asserts the
 * migration happened at all, which is a separate claim and the one that fails silently.
 */
class EmbedTypeRegistryConformanceTest extends TestCase
{
    /**
     * The tripwire (27 D3). A package whose harness omits PopcornServiceProvider gets a fresh
     * RegistryIndex per make(), so every describe() lands on a throwaway and every assertion below
     * passes vacuously over an empty index. Assert the sharing before believing any of them.
     */
    public function test_the_registry_index_is_a_shared_singleton(): void
    {
        $this->assertSame($this->app->make(RegistryIndex::class), $this->app->make(RegistryIndex::class));
    }

    public function test_the_registry_conforms_to_the_kernel_contract(): void
    {
        $this->assertInstanceOf(Registry::class, $this->app->make(EmbedTypeRegistry::class));
    }

    public function test_the_provider_describes_the_root_into_the_shared_index(): void
    {
        $keys = array_map(strval(...), $this->app->make(RegistryIndex::class)->keys());

        $this->assertContains('beam.embed.types', $keys);
    }

    /**
     * The round trip through the port's OWN vocabulary — the one-argument self-keying `register()`
     * every historical caller uses (tower's `register(new ConciergeEmbedType)`), read back through
     * `get()`/`all()`, plus the keyspace's spelling of the same entries: relative in, absolute out.
     */
    public function test_a_registration_round_trips_through_the_ports_own_vocabulary(): void
    {
        $registry = $this->app->make(EmbedTypeRegistry::class);

        $registry->register(new FakeEmbedType('concierge'));

        $this->assertTrue($registry->has('concierge'));
        $this->assertSame('concierge', $registry->get('concierge')->key());
        $this->assertSame(
            [GenericEmbedType::Key, 'concierge'],
            array_keys($registry->all()),
        );
        $this->assertSame(
            ['beam.embed.types.generic', 'beam.embed.types.concierge'],
            array_map(strval(...), $registry->keys()),
        );
    }

    public function test_an_absolute_type_key_routes_back_to_the_registry(): void
    {
        $this->assertSame(
            $this->app->make(EmbedTypeRegistry::class),
            $this->app->make(RegistryIndex::class)->routeTo('beam.embed.types.generic'),
        );
    }
}
