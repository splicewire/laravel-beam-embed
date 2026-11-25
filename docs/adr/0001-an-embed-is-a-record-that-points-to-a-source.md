# ADR-0001 — An Embed is a record that points to a source

## Status

Accepted for owner-review-20261112 ticket 35.

## Context

An embed's identity and publication policy used to be carried by a Thread row. That couples remote publishing to conversation sources and makes the public key identify a conversation. Other registered source kinds, such as schemas, need the same publishing and type boundary without being conversations.

## Decision

Store an Embed in its own `embeds` table. It owns the type, source morph, policy and retention. The source remains the owner of its content; a conversation-backed Embed delegates conversation and version operations to its Thread source. Central EmbedKey rows point to the Embed ID.

Sources register a resolver under their morph alias. A resolver supplies the display name and, when the source determines it, a fixed type. Otherwise the requested type must be registered and declare that it accepts the source kind. Creation and first publication share the same publisher path.

Existing published Thread templates are backfilled to Embed rows that point to their Thread, preserving their IDs so central keys can be renamed from `published_chat_id` to `embed_id` without changing the pointer value.

## Consequences

- Non-conversation sources can be represented without creating a Thread.
- Conversation content and version history stay on the Thread; Embed identity and keys are separate.
- A key, session's `published_from_id`, and public embed routes identify the Embed. Consumers that need the source conversation follow the source relation.
- Hosts publish the tenant and central migration stubs through the Beam install manifest.
