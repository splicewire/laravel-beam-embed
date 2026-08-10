> You are in **splicewire/laravel-beam-embed** — the generic embed subsystem for the beam family.

Spawner / publisher / budget / usage-meter / retention / escalation / groundedness-signal /
humanity-check, plus the capability grant/token/tier value objects and the embed models (`Embed`,
`Visitor`, `EmbedKey`, `EmbedSpendReservation`, `EmbedCapabilityAudit`). KNOWLEDGE-grounding is
inverted behind two ports (`KnowledgePivotFreezer`, `ScopeResolver`): beam-embed ships no-op
defaults so a bare beam site works, and a knowledge-aware host binds the real implementations.
Depends down on beam-core, beam-commerce, and beam-tenancy; never onto a host application.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
