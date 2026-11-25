# splicewire/laravel-beam-embed

The generic embed subsystem for Beam hosts: publishing an embed, spawning its sessions, budgeting and
metering its spend, retention, escalation, the groundedness signal and the humanity check, plus the
capability grant, token and tier value objects and the embed models (`Embed`, `Visitor`, `EmbedKey`,
`EmbedSpendReservation`, `EmbedCapabilityAudit`).

An Embed is its own record. It stores its registered type and a morph to its source; the source keeps its
content. Conversation operations on an Embed delegate to its Thread source. Keys and spawned sessions
refer to the Embed ID, not the source ID.

Knowledge grounding sits behind two ports, `KnowledgePivotFreezer` and `ScopeResolver`. The package binds
no-op defaults, so a bare Beam site works, and a knowledge-aware host binds the real implementations. Routes,
controllers and config stay with the host.

## Install

Require `splicewire/laravel-beam-embed`, then run `php artisan splicewire:beam:install`. The install step
publishes the package's tenant and central migration stubs; the host runs them in its tenant and central
migration passes.

## Create and publish

A host registers an `EmbedSourceResolver` for each supported source morph alias. The resolver returns a
display name and either a fixed type or `null`. A fixed type is derived when omitted and a conflicting
request is refused. For an open source, the requested type must be registered and its `sourceKinds()`
must include the source alias.

Tower exposes draft creation as `POST embeds`, with `sourceKind`, `sourceId`, and an optional `type`.
The first `POST embeds/{id}/publish` creates the key and returns its plaintext once; later calls republish
the source content without returning the plaintext again. The same `EmbedPublisher` path serves both HTTP
creation/publication and host provisioning commands.

## Example: serve your own embed type

An embed type is one flavour of remote embed, with the handlers behind the capabilities a widget can call.
The package registers `generic`, which refuses every capability. Register your own from a provider and make
it the default:

```php
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;

$this->callAfterResolving(EmbedTypeRegistry::class, function (EmbedTypeRegistry $registry) {
    $registry->register(new ConciergeEmbedType);
    $registry->setDefault(ConciergeEmbedType::Key);
});
```

## Registries

This package declares one registry:

- [`beam.embed.types`](docs/registries/beam.embed.types.mdx): the embed types a host serves. The package
  registers `generic` into it.

It registers into three registries `splicewire/laravel-beam` owns:

- [`beam.install.steps`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.install.steps.mdx):
  the install step that publishes the migration stubs.
- [`beam.doctor.audits`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.doctor.audits.mdx):
  the audit of the package's own published migrations.
- [`beam.particle.resource-contributions`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.resource-contributions.mdx):
  the `embed` slice of the `me` resource, which carries the support launcher's publishable key.

See [ADR-0001](docs/adr/0001-an-embed-is-a-record-that-points-to-a-source.md) for the record/source boundary.

## Testing

Run `composer test`.
