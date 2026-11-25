<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Rushing\PermissionCascade\Concerns\HasUserId;
use Rushing\PermissionCascade\Concerns\HasVisibility;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Threads\Contracts\Conversation;

/**
 * An independently addressed embed, typed by its host registration and backed by a polymorphic source.
 * Conversation content remains on the source; policy, retention, type and keys belong to this record.
 */
class Embed extends Model implements Conversation
{
    /** Fields Tower reads from the conversation source through an Embed. */
    private const PROJECTED_CONVERSATION_ATTRIBUTES = [
        'model',
        'instructions_text',
        'template_version',
    ];

    use HasUserId;
    use HasUuids;
    use HasVisibility;

    protected $table = 'embeds';

    protected $fillable = [
        'type',
        'source_type',
        'source_id',
        'user_id',
        'visibility',
        'embed_policy',
        'retention_days',
    ];

    protected function casts(): array
    {
        return [
            'embed_policy' => 'array',
            'retention_days' => 'integer',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The central keys pointing at this tenant-local embed.
     *
     * @return Collection<int, EmbedKey>
     */
    public function keys(): Collection
    {
        return EmbedKey::query()->where('embed_id', $this->getKey())->latest('created_at')->get();
    }

    public function liveKey(): ?EmbedKey
    {
        return EmbedKey::query()
            ->where('embed_id', $this->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();
    }

    public function isPublished(): bool
    {
        return $this->exists && $this->keys()->isNotEmpty();
    }

    public function headVersion(): ?Version
    {
        return $this->conversationSource()->headVersion();
    }

    public function snapshotVersion(?string $label = null): Version
    {
        return $this->conversationSource()->snapshotVersion($label);
    }

    public function versionable(): Conversation
    {
        return $this->conversationSource()->versionable();
    }

    public function versionableRecord(): Conversation
    {
        $source = $this->conversationSource();

        if (! $source instanceof Model) {
            throw new \LogicException('This embed source is not an Eloquent versionable record.');
        }

        $record = $source->newQuery()->findOrFail($source->getKey());

        if (! $record instanceof Conversation) {
            throw new \LogicException('This embed source does not resolve to a versionable conversation.');
        }

        return $record;
    }

    public function toVersionSnapshot(): array
    {
        return $this->conversationSource()->toVersionSnapshot();
    }

    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->conversationSource()->restoreVersionSnapshot($snapshot);
    }

    public function versionPinAttribute(): string
    {
        return $this->conversationSource()->versionPinAttribute();
    }

    public function messages(): HasMany
    {
        return $this->conversationSource()->messages();
    }

    /** Conversation fields remain projections of the source record. */
    public function getAttribute($key)
    {
        $attribute = parent::getAttribute($key);

        if ($attribute !== null || array_key_exists($key, $this->attributes)) {
            return $attribute;
        }

        // An absent own column is still owned by this model. In particular, allowing `id` to
        // fall through makes HasUuids think the source key is already set and omit Embed id.
        $ownColumns = [
            $this->getKeyName(),
            ...$this->getFillable(),
            'user_id',
            'visibility',
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];
        if (in_array($key, $ownColumns, true)) {
            return null;
        }

        if (in_array($key, self::PROJECTED_CONVERSATION_ATTRIBUTES, true)) {
            return $this->source?->getAttribute($key);
        }

        return $attribute;
    }

    private function conversationSource(): Conversation
    {
        $source = $this->source;

        if (! $source instanceof Conversation) {
            throw new \LogicException('This embed source does not provide conversation versioning.');
        }

        return $source;
    }
}
