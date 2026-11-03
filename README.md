# splicewire/laravel-beam-embed

The generic embed subsystem for Beam hosts: publishing an embed, spawning its sessions, budgeting and
metering its spend, retention, escalation, the groundedness signal and the humanity check, plus the
capability grant, token and tier value objects and the embed models (`Embed`, `Visitor`, `EmbedKey`,
`EmbedSpendReservation`, `EmbedCapabilityAudit`).

Knowledge grounding sits behind two ports, `KnowledgePivotFreezer` and `ScopeResolver`. The package binds
no-op defaults, so a bare Beam site works, and a knowledge-aware host binds the real implementations. Routes,
controllers and config stay with the host.

## Install

Require `splicewire/laravel-beam-embed`, then run `php artisan splicewire:beam:install`. The install step
publishes the package's tenant migrations and migrates.

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
  the install step that publishes the tenant migrations.
- [`beam.doctor.audits`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.doctor.audits.mdx):
  the audit of the package's own published migrations.
- [`beam.particle.resource-contributions`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.resource-contributions.mdx):
  the `embed` slice of the `me` resource, which carries the support launcher's publishable key.

## Testing

Run `composer test`.
