<?php

namespace Splicewire\Beam\Embed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Embed\Models\EmbedKey;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Rushing\PermissionCascade\Concerns\HasUserId;
use Rushing\PermissionCascade\Concerns\HasVisibility;

/**
 * An independently addressed embed, typed by its host registration and backed by a polymorphic source.
 * Conversation content remains on the source; policy, retention, type and keys belong to this record.
 */
class Embed extends Model implements Conversation
{
    use HasUuids;
    use HasUserId;
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
        return $this->conversationSource()->versionableRecord();
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

        if ($attribute !== null || array_key_exists($key, $this->attributes) || in_array($key, [
            'embed_policy', 'retention_days', 'type', 'source_type', 'source_id', 'created_at', 'updated_at',
        ], true)) {
            return $attribute;
        }

        return $this->source?->getAttribute($key);
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
