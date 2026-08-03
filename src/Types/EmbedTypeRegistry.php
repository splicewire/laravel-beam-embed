<?php

namespace Splicewire\Beam\Embed\Types;

use InvalidArgumentException;

/**
 * The keyed registry of embed TYPES (recohere T30) — the seam a second type plugs into. beam-embed
 * registers the generic {@see GenericEmbedType} as the default; a host registers its own (tower's
 * `concierge`) in its service provider, and a future satellite would register `chat-widget` here
 * WITHOUT touching tower. The generic api/embed surface resolves the ACTIVE type's broker + handlers
 * from this registry, so which flavour of remote-embed is live is a registration decision, not a
 * hard-coded branch.
 *
 * Registered as a singleton so registrations from every provider accumulate onto one instance.
 */
class EmbedTypeRegistry
{
    /** @var array<string, EmbedType> */
    protected array $types = [];

    protected ?string $default = null;

    /**
     * Register (or replace) a type under its own {@see EmbedType::key()}. The first registered type
     * becomes the default active type unless a later {@see setDefault()} overrides it.
     */
    public function register(EmbedType $type): void
    {
        $this->types[$type->key()] = $type;

        $this->default ??= $type->key();
    }

    public function setDefault(string $key): void
    {
        $this->default = $key;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * Resolve a type by key. Passing null resolves the ACTIVE (default) type — the flavour of
     * remote-embed a request is served under when the embed does not name one explicitly.
     */
    public function get(?string $key = null): EmbedType
    {
        $key ??= $this->default;

        if ($key === null || ! isset($this->types[$key])) {
            throw new InvalidArgumentException(
                $key === null
                    ? 'No embed type is registered.'
                    : "Unknown embed type [{$key}]."
            );
        }

        return $this->types[$key];
    }

    /**
     * The active (default) type — the one selected when no explicit `embed_type` is present.
     */
    public function active(): EmbedType
    {
        return $this->get(null);
    }

    /**
     * @return array<string, EmbedType>
     */
    public function all(): array
    {
        return $this->types;
    }
}
