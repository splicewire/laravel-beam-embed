<?php

namespace Splicewire\Beam\Embed\Tests\Types;

use InvalidArgumentException;
use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Tests\Fakes\FakeEmbedType;
use Splicewire\Beam\Embed\Tests\Fixtures\EmbedFixtureServiceProvider;
use Splicewire\Beam\Embed\Tests\TestCase;
use Splicewire\Beam\Embed\Types\EmbedType;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Embed\Types\GenericEmbedType;
use Splicewire\Beam\Embed\Types\Handlers\UnsupportedCapabilityHandler;

/**
 * The registry decides WHICH flavour of remote-embed serves a request when the request names none —
 * "first registration wins the default" is load-bearing and entirely implicit (`$this->default ??=`).
 * A host registers its own type in a provider that boots AFTER beam-embed's, so if that `??=` ever
 * became a plain `=`, every bare request would silently flip from the host's real type to whichever
 * type registered last, and brokered calls would start returning `capability_unsupported` on a system
 * that had been working.
 *
 * The singleton binding is asserted for the same reason: registrations accumulate from several
 * providers onto ONE instance. Rebind it as a plain `bind()` and the host's registration is thrown away
 * the moment anything else resolves the registry.
 */
class EmbedTypeRegistryTest extends TestCase
{
    public function test_the_type_contract_declares_the_fields_consumers_need_to_list_a_type(): void
    {
        $contract = new \ReflectionClass(EmbedType::class);

        foreach (['label', 'sourceKinds', 'configSchema', 'clientScriptUrl'] as $method) {
            $this->assertTrue($contract->hasMethod($method), "EmbedType must declare {$method}().");
        }
    }

    public function test_all_exposes_the_full_registered_type_declaration_from_a_fixture_provider(): void
    {
        $this->app->register(EmbedFixtureServiceProvider::class);

        $type = $this->app->make(EmbedTypeRegistry::class)->all()['fixture'];

        $this->assertSame('fixture', $type->key());
        $this->assertSame('Fixture embed', $type->label());
        $this->assertSame(['or35-probe-source'], $type->sourceKinds());
        $this->assertSame(
            ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            $type->configSchema(),
        );
        $this->assertSame('https://example.test/fixture.js', $type->clientScriptUrl());
    }

    public function test_the_first_registration_becomes_the_default_and_later_ones_do_not_displace_it(): void
    {
        $registry = new EmbedTypeRegistry;
        $registry->register(new FakeEmbedType('first'));
        $registry->register(new FakeEmbedType('second'));

        $this->assertSame('first', $registry->active()->key());
        $this->assertSame('first', $registry->get()->key());
        $this->assertSame('second', $registry->get('second')->key());
    }

    public function test_a_host_can_take_over_the_active_type_explicitly(): void
    {
        $registry = new EmbedTypeRegistry;
        $registry->register(new FakeEmbedType('generic'));
        $registry->register(new FakeEmbedType('concierge'));

        $registry->setDefault('concierge');

        $this->assertSame('concierge', $registry->active()->key());
    }

    public function test_re_registering_a_key_replaces_the_type_in_place(): void
    {
        $registry = new EmbedTypeRegistry;
        $registry->register(new FakeEmbedType('generic', ['resolve' => UnsupportedCapabilityHandler::class]));
        $registry->register(new FakeEmbedType('generic', []));

        $this->assertCount(1, $registry->all());
        $this->assertSame([], $registry->active()->capabilityHandlers());
    }

    public function test_an_unknown_key_throws_naming_the_key(): void
    {
        $registry = new EmbedTypeRegistry;
        $registry->register(new FakeEmbedType('generic'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown embed type [nope].');

        $registry->get('nope');
    }

    public function test_an_empty_registry_says_so_rather_than_returning_null(): void
    {
        // Distinct message from the unknown-key case: "no type is registered" points an operator at a
        // missing provider, "unknown type" points at a bad request. Collapsing them costs a debugging hour.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No embed type is registered.');

        (new EmbedTypeRegistry)->get();
    }

    public function test_the_provider_stands_up_a_shared_registry_defaulting_to_the_generic_type(): void
    {
        $registry = $this->app->make(EmbedTypeRegistry::class);

        $this->assertSame(GenericEmbedType::Key, $registry->active()->key());
        $this->assertSame($registry, $this->app->make(EmbedTypeRegistry::class), 'the registry must be a singleton');
    }

    public function test_the_generic_type_is_wired_but_denies_every_capability(): void
    {
        // A bare beam site gets the whole machinery and refuses — that posture is the reason a brokered
        // call on an unconfigured site returns 501 rather than exposing an unscoped read.
        $type = new GenericEmbedType;

        $this->assertSame(CapabilityBroker::class, $type->brokerClass());

        foreach (Capability::cases() as $capability) {
            $this->assertArrayHasKey($capability->value, $type->capabilityHandlers());
            $this->assertSame(
                UnsupportedCapabilityHandler::class,
                $type->capabilityHandlers()[$capability->value],
            );
        }
    }
}
