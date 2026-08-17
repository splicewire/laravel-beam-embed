<?php

namespace Splicewire\Beam\Embed\Tests\Capability;

use Splicewire\Beam\Embed\Capability\BrokerResult;
use Splicewire\Beam\Embed\Capability\Capability;
use Splicewire\Beam\Embed\Capability\CapabilityBroker;
use Splicewire\Beam\Embed\Capability\CapabilityGrant;
use Splicewire\Beam\Embed\Capability\CapabilityToken;
use Splicewire\Beam\Embed\Capability\TrustTier;
use Splicewire\Beam\Embed\Tests\Fakes\FakeEmbedType;
use Splicewire\Beam\Embed\Tests\Fakes\RecordingAuditRecorder;
use Splicewire\Beam\Embed\Tests\Fakes\SpyCapabilityHandler;
use Splicewire\Beam\Embed\Tests\TestCase;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;

/**
 * The broker is the ONLY path authority flows through for an isolated remote guest, and its contract is
 * "refuse BEFORE any handler runs, and audit every outcome either way". Both halves are easy to break
 * without noticing:
 *
 *  - reordering the checks (e.g. resolving the handler before the grant check) would let a
 *    not-granted capability reach host code — the refusal would still be returned, so a test asserting
 *    only on the response would stay green while the side effect had already happened. Hence every
 *    refusal case here asserts the handler was NEVER called;
 *  - dropping an audit write on one branch leaves an untraceable hole in the durable record of what a
 *    guest asked the host to run. Hence every case asserts the audit row, including the refusals where
 *    there is no trustworthy grant to attribute.
 *
 * The status codes are asserted because they are the guest-facing contract (401 forged, 403 policy,
 * 422 nonsense, 501 unimplemented) and nothing else in the package pins them.
 */
class CapabilityBrokerTest extends TestCase
{
    private RecordingAuditRecorder $audit;

    private EmbedTypeRegistry $registry;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = new RecordingAuditRecorder;
        $this->registry = new EmbedTypeRegistry;
    }

    private function broker(): CapabilityBroker
    {
        return new CapabilityBroker($this->registry, $this->audit, $this->app);
    }

    private function grant(
        TrustTier $tier = TrustTier::UntrustedPublisher,
        ?array $capabilities = null,
        string $audience = 'pk_alpha',
    ): CapabilityGrant {
        return new CapabilityGrant(
            tier: $tier,
            capabilities: $capabilities ?? $tier->defaultCapabilities(),
            embedKeyId: $audience,
            sessionId: 'session-1',
        );
    }

    private function jwt(?CapabilityGrant $grant = null, int $ttl = 300): string
    {
        $now = time();

        return (new CapabilityToken($grant ?? $this->grant(), $now, $now + $ttl))->toJwt();
    }

    private function registerType(SpyCapabilityHandler $handler, string $capability = 'resolve'): void
    {
        $this->registry->register(new FakeEmbedType('test', [$capability => $handler]));
    }

    public function test_an_authorized_call_reaches_the_handler_and_returns_its_result(): void
    {
        $handler = new SpyCapabilityHandler(BrokerResult::allow(['title' => 'Resolved']));
        $this->registerType($handler);

        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'resolve', ['ref' => '@id/1']);

        $this->assertTrue($result->allowed);
        $this->assertSame(['title' => 'Resolved'], $result->data);
        $this->assertSame(200, $result->status);

        // The handler receives the VERIFIED grant, not the raw claims — it must be able to trust the
        // scope/tier it reads off it.
        $this->assertSame(1, $handler->calls);
        $this->assertSame('session-1', $handler->receivedGrant->sessionId);
        $this->assertSame(['ref' => '@id/1'], $handler->receivedPayload);

        $this->assertCount(1, $this->audit->records);
        $this->assertTrue($this->audit->last()['allowed']);
        $this->assertNull($this->audit->last()['reason']);
        $this->assertSame('pk_alpha', $this->audit->last()['embedKeyId']);
    }

    public function test_a_forged_token_is_refused_401_and_audited_with_no_grant(): void
    {
        $handler = new SpyCapabilityHandler;
        $this->registerType($handler);

        $result = $this->broker()->invoke('garbage.token.here', 'pk_alpha', 'resolve', ['ref' => 'x']);

        $this->assertFalse($result->allowed);
        $this->assertSame('invalid_token', $result->reason);
        $this->assertSame(401, $result->status);
        $this->assertSame(0, $handler->calls);

        // No claim is trustworthy on a bad signature, so the row is attributed to the audience the
        // REQUEST resolved to and carries a null grant — that is what makes it still attributable.
        $this->assertCount(1, $this->audit->records);
        $this->assertNull($this->audit->last()['grant']);
        $this->assertSame('pk_alpha', $this->audit->last()['embedKeyId']);
        $this->assertSame('invalid_token', $this->audit->last()['reason']);
    }

    public function test_a_token_minted_for_another_embed_is_refused_as_a_forgery(): void
    {
        $handler = new SpyCapabilityHandler;
        $this->registerType($handler);

        $result = $this->broker()->invoke($this->jwt(), 'pk_beta', 'resolve', []);

        $this->assertFalse($result->allowed);
        $this->assertSame('invalid_token', $result->reason);
        $this->assertSame(401, $result->status);
        $this->assertSame(0, $handler->calls);
    }

    public function test_an_expired_token_is_refused_before_the_capability_is_even_parsed(): void
    {
        $handler = new SpyCapabilityHandler;
        $this->registerType($handler);

        $result = $this->broker()->invoke($this->jwt(ttl: -10), 'pk_alpha', 'resolve', []);

        $this->assertFalse($result->allowed);
        $this->assertSame('expired', $result->reason);
        $this->assertSame(403, $result->status);
        $this->assertSame(0, $handler->calls);
        $this->assertSame('expired', $this->audit->last()['reason']);
    }

    public function test_an_unknown_capability_name_is_a_422_not_a_500(): void
    {
        $handler = new SpyCapabilityHandler;
        $this->registerType($handler);

        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'drop_database', []);

        $this->assertFalse($result->allowed);
        $this->assertSame('unknown_capability', $result->reason);
        $this->assertSame(422, $result->status);
        $this->assertSame(0, $handler->calls);

        // Audited under the raw name the guest asked for — otherwise a probe for capability names would
        // leave no trace.
        $this->assertSame('drop_database', $this->audit->last()['capability']);
    }

    public function test_a_capability_outside_the_grant_never_reaches_the_handler(): void
    {
        $handler = new SpyCapabilityHandler;
        $this->registry->register(new FakeEmbedType('test', [
            Capability::RequestSave->value => $handler,
        ]));

        // An untrusted publisher's tier stops at read; the handler for `request_save` exists and would
        // happily run if the grant check were reordered after handler resolution.
        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'request_save', ['body' => 'x']);

        $this->assertFalse($result->allowed);
        $this->assertSame('capability_not_granted', $result->reason);
        $this->assertSame(403, $result->status);
        $this->assertSame(0, $handler->calls);
    }

    public function test_a_granted_capability_the_active_type_does_not_implement_is_a_501(): void
    {
        // The type registers only `resolve`; `read_scoped` is inside the grant but unimplemented here.
        $this->registerType(new SpyCapabilityHandler, 'resolve');

        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'read_scoped', []);

        $this->assertFalse($result->allowed);
        $this->assertSame('capability_unsupported', $result->reason);
        $this->assertSame(501, $result->status);
    }

    public function test_a_handler_refusal_is_passed_through_and_audited_with_its_own_reason(): void
    {
        // The type's handler owns scope enforcement; the broker must not overwrite or swallow its reason,
        // or an out-of-scope read would be indistinguishable from a policy refusal in the audit trail.
        $handler = new SpyCapabilityHandler(BrokerResult::refuse('out_of_scope', 403));
        $this->registerType($handler);

        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'resolve', []);

        $this->assertFalse($result->allowed);
        $this->assertSame('out_of_scope', $result->reason);
        $this->assertSame(1, $handler->calls);

        $this->assertFalse($this->audit->last()['allowed']);
        $this->assertSame('out_of_scope', $this->audit->last()['reason']);
    }

    public function test_a_handler_declared_as_a_class_string_is_container_resolved(): void
    {
        $handler = new SpyCapabilityHandler(BrokerResult::allow('via-container'));
        $this->app->instance(SpyCapabilityHandler::class, $handler);

        $this->registry->register(new FakeEmbedType('test', [
            Capability::Resolve->value => SpyCapabilityHandler::class,
        ]));

        $result = $this->broker()->invoke($this->jwt(), 'pk_alpha', 'resolve', []);

        $this->assertTrue($result->allowed);
        $this->assertSame('via-container', $result->data);
        $this->assertSame(1, $handler->calls);
    }

    public function test_the_named_embed_type_selects_which_handlers_service_the_call(): void
    {
        $active = new SpyCapabilityHandler(BrokerResult::allow('from-active'));
        $other = new SpyCapabilityHandler(BrokerResult::allow('from-other'));

        $this->registry->register(new FakeEmbedType('test', [Capability::Resolve->value => $active]));
        $this->registry->register(new FakeEmbedType('other', [Capability::Resolve->value => $other]));

        // Null = the ACTIVE (first-registered) type.
        $this->assertSame('from-active', $this->broker()->invoke($this->jwt(), 'pk_alpha', 'resolve', [])->data);
        $this->assertSame('from-other', $this->broker()->invoke($this->jwt(), 'pk_alpha', 'resolve', [], 'other')->data);
    }

    public function test_a_first_party_token_reaches_the_save_capability(): void
    {
        // The positive control for the tier ceiling: the same handler that an untrusted publisher is
        // refused on is reachable under the first-party tier, so the earlier refusal is a policy
        // decision and not a permanently-broken path.
        $handler = new SpyCapabilityHandler(BrokerResult::allow('saved'));
        $this->registry->register(new FakeEmbedType('test', [
            Capability::RequestSave->value => $handler,
        ]));

        $result = $this->broker()->invoke(
            $this->jwt($this->grant(TrustTier::FirstParty)),
            'pk_alpha',
            'request_save',
            ['body' => 'x'],
        );

        $this->assertTrue($result->allowed);
        $this->assertSame(1, $handler->calls);
    }
}
