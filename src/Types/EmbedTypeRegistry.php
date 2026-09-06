<?php

namespace Splicewire\Beam\Embed\Types;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The keyed registry of embed TYPES (recohere T30) — the seam a second type plugs into. beam-embed
 * registers the generic {@see GenericEmbedType} as the default; a host registers its own (tower's
 * `concierge`) in its service provider, and a future satellite would register `chat-widget` here
 * WITHOUT touching tower. The generic api/embed surface resolves the ACTIVE type's broker + handlers
 * from this registry, so which flavour of remote-embed is live is a registration decision, not a
 * hard-coded branch.
 *
 * Registered as a singleton so registrations from every provider accumulate onto one instance.
 *
 * ## Conformed to the Popcorn kernel (registry-kernel ticket 38)
 *
 * A keyed registry: hand-registration, no registrar, no behaviour change. The private
 * `[key => EmbedType]` array is gone; a composed {@see BasicRegistry} is the storage, and every one of
 * this class's own verbs became sugar over the contract. `beam.embed.types` is the branch of the
 * keyspace it owns, so tower's concierge type is addressable as `beam.embed.types.concierge` through
 * the shared index and not only through this object.
 *
 * ⚠️ **`get()` deliberately keeps throwing `InvalidArgumentException`, not the kernel's `RegistryMiss`.**
 * The contract's own {@see resolve()} throws `RegistryMiss` like every other conforming registry — but
 * `get()` is this port's vocabulary, and its TWO distinct messages are load-bearing: *"No embed type is
 * registered"* points an operator at a missing provider, *"Unknown embed type [x]"* points at a bad
 * request, and the second is a case `RegistryMiss` cannot express because an empty registry has no key
 * to miss on. Collapsing them was measured as costing a debugging hour, so the sugar keeps the
 * distinction and callers asserting on it keep working.
 *
 * ## `$default` is not a second keyspace
 *
 * It is a single scalar POINTER into the one keyspace — "which registered type serves a request that
 * names none" — set implicitly by the first registration (`??=`) and overridable by
 * {@see setDefault()}. It is not a parallel array and encodes no precedence rule, so it survives the
 * migration untouched rather than becoming an ordering the kernel would have to express.
 */
#[IsRegistry(
    root: 'beam.embed.types',
    entryType: EmbedType::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    description: 'pluggable remote-embed types — one per flavour of embed a host serves. The FIRST registration also becomes the active default unless setDefault() overrides it, so provider boot order is observable here: beam-embed seeds `generic` from its own register phase and a host type registered later must claim the default explicitly.',
)]
class EmbedTypeRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    protected ?string $default = null;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register (or replace) a type under its own {@see EmbedType::key()}. The first registered type
     * becomes the default active type unless a later {@see setDefault()} overrides it.
     *
     * WIDENED contravariantly from the contract rather than shadowing it, so the historical
     * one-argument self-keying call (`$registry->register(new ConciergeEmbedType)`) keeps working
     * alongside the kernel's four-argument form.
     */
    public function register(RegistryKey|string|EmbedType $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof EmbedType) {
            $entry = $key;
            $key = $key->key();
        }

        $this->entries->register($key, $entry, $by, $ability);

        // The domain spelling, never the absolute key: `default` is compared against `EmbedType::key()`
        // by every caller and by setDefault(), which are relative.
        $this->default ??= $entry instanceof EmbedType ? $entry->key() : (string) $key;

        return $this;
    }

    public function setDefault(string $key): void
    {
        $this->default = $key;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    /**
     * Resolve a type by key. Passing null resolves the ACTIVE (default) type — the flavour of
     * remote-embed a request is served under when the embed does not name one explicitly.
     *
     * @throws InvalidArgumentException see the class docblock: the two messages are the point
     */
    public function get(?string $key = null): EmbedType
    {
        $key ??= $this->default;

        $type = $key === null ? null : $this->entries->tryResolve($key);

        if (! $type instanceof EmbedType) {
            throw new InvalidArgumentException(
                $key === null
                    ? 'No embed type is registered.'
                    : "Unknown embed type [{$key}]."
            );
        }

        return $type;
    }

    /**
     * The active (default) type — the one selected when no explicit `embed_type` is present.
     */
    public function active(): EmbedType
    {
        return $this->get(null);
    }

    /**
     * Every registered type, keyed as the registrant spelled it — rebuilt from `relativeKeys()`
     * rather than kept beside the store, because keys go relative in and absolute out (20 D2) and a
     * caller-facing map wants the caller's spelling.
     *
     * @return array<string, EmbedType>
     */
    public function all(): array
    {
        $types = [];

        foreach ($this->entries->relativeKeys() as $key) {
            $types[$key] = $this->entries->resolve($key);
        }

        return $types;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }
}
