<?php

namespace Splicewire\Beam\Embed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Embed\Models\Embed;
use Splicewire\Beam\Embed\Models\EmbedKey;
use Splicewire\Beam\Embed\Models\Thread;
use Splicewire\Beam\Embed\Sources\EmbedSourceRegistry;
use Splicewire\Beam\Embed\Types\EmbedTypeRegistry;
use Splicewire\Beam\Threads\Contracts\Conversation;
use Splicewire\Beam\Threads\Enums\ThreadKind;

class EmbedPublisher
{
    public const LAUNCHER_COSMETIC_KEYS = [
        'enabled', 'position', 'accentColor', 'iconUrl', 'greeting', 'title',
    ];

    public function __construct(
        private EmbedSourceRegistry $sources,
        private EmbedTypeRegistry $types,
    ) {}

    /** Create a typed, unpublished Embed record for its source. */
    public function create(Model $source, ?string $type = null): Embed
    {
        if (! $source->exists) {
            throw new InvalidArgumentException('An embed source must be a persisted model.');
        }

        $alias = $source->getMorphClass();
        $resolution = $this->sources->resolve($source);

        if ($resolution->fixedType !== null) {
            if ($type !== null && $type !== $resolution->fixedType) {
                throw new InvalidArgumentException("Embed source [{$alias}] fixes type [{$resolution->fixedType}].");
            }
            $type = $resolution->fixedType;
        }

        if ($type === null) {
            throw new InvalidArgumentException("An embed type is required for open source [{$alias}].");
        }

        $embedType = $this->types->get($type);
        if (! in_array($alias, $embedType->sourceKinds(), true)) {
            throw new InvalidArgumentException("Embed type [{$type}] does not accept source kind [{$alias}].");
        }

        $embed = new Embed;
        $embed->type = $type;
        $embed->user_id = $source->getAttribute('user_id');
        $embed->visibility = $source->getAttribute('visibility');
        $embed->source()->associate($source);
        $embed->save();

        return $embed;
    }

    /**
     * Publish from a source, or publish an already-created Embed. The returned plaintext is shown once.
     *
     * @param  list<string>  $allowedOrigins
     * @param  array<string, mixed>|null  $launcher
     * @return array{embed: Embed, plaintext: string, key: EmbedKey}
     */
    public function publish(
        Conversation|Embed $source,
        array $allowedOrigins = [],
        ?int $retentionDays = null,
        ?float $walletCreditUsd = null,
        bool $enabled = true,
        ?array $launcher = null,
        bool $selfDeliver = false,
    ): array {
        $embed = $source instanceof Embed ? $source : $this->create($source);
        $conversation = $embed->source;

        if ($conversation instanceof Conversation) {
            $conversation->update([
                'kind' => ThreadKind::EmbedTemplate,
                'template_version' => ($conversation->template_version ?? 0) + 1,
            ]);
            $this->baseThread($conversation)->snapshotVersion('published');
        }

        $policy = array_filter([
            'allowed_origins' => $allowedOrigins,
            'wallet_credit_usd' => $walletCreditUsd,
            'enabled' => $enabled,
        ], fn ($value) => $value !== null && $value !== []);

        if ($launcher !== null) {
            $cosmetic = Arr::only($launcher, self::LAUNCHER_COSMETIC_KEYS);
            if ($cosmetic !== []) {
                $policy['launcher'] = $cosmetic;
            }
        }

        $embed->forceFill([
            'embed_policy' => $policy,
            'retention_days' => $retentionDays,
        ])->save();

        [$key, $plaintext] = EmbedKey::issue(
            (string) tenant()?->getTenantKey(),
            (string) $embed->getKey(),
            $allowedOrigins,
            $selfDeliver,
        );

        return ['embed' => $embed, 'plaintext' => $plaintext, 'key' => $key];
    }

    /** @return array{0: EmbedKey, 1: string} */
    public function replaceKey(Embed $embed, string $tenantId): array
    {
        $connection = (new EmbedKey)->getConnection();

        return $connection->transaction(function () use ($embed, $tenantId, $connection) {
            $tenantClass = config('tenancy.tenant_model');
            $tenantModel = new $tenantClass;
            $tenant = $connection->table($tenantModel->getTable())
                ->where($tenantModel->getTenantKeyName(), $tenantId)
                ->lockForUpdate()
                ->first();
            if ($tenant === null) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel($tenantClass, [$tenantId]);
            }

            $keys = EmbedKey::query()
                ->where('tenant_id', $tenantId)
                ->where('embed_id', $embed->getKey())
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->get();
            $previous = $keys->firstWhere('revoked_at', null) ?? $keys->first();
            [$key, $plaintext] = EmbedKey::issue(
                $tenantId,
                (string) $embed->getKey(),
                $embed->embed_policy['allowed_origins'] ?? [],
                (bool) $previous?->self_deliver,
            );
            foreach ($keys as $old) {
                $old->revoke();
            }

            return [$key, $plaintext];
        });
    }

    /** @param array<string, mixed> $fields */
    public function updateInstructions(Embed $embed, array $fields): void
    {
        $source = $this->conversationSource($embed);
        $staged = Arr::only($fields, self::STAGED_INSTRUCTION_KEYS);
        if ($staged !== []) {
            $source->update($staged);
        }
    }

    public const STAGED_INSTRUCTION_KEYS = [
        'instructions_provider', 'instructions_text', 'instructions_schemas', 'model', 'title', 'max_tool_steps',
    ];

    public function republish(Embed $embed, ?string $label = null): Version
    {
        $source = $this->conversationSource($embed);
        $source->update(['template_version' => ($source->template_version ?? 0) + 1]);

        return $this->baseThread($source)->snapshotVersion($label);
    }

    public function updateConfig(
        Embed $embed,
        ?array $allowedOrigins = null,
        ?float $walletCreditUsd = null,
        ?bool $enabled = null,
        ?array $launcher = null,
        bool $walletProvided = false,
    ): void {
        $policy = $embed->embed_policy ?? [];

        if ($enabled !== null) {
            $policy['enabled'] = $enabled;
        }
        if ($walletProvided) {
            if ($walletCreditUsd === null) {
                unset($policy['wallet_credit_usd']);
            } else {
                $policy['wallet_credit_usd'] = $walletCreditUsd;
            }
        }
        if ($allowedOrigins !== null) {
            if ($allowedOrigins === []) {
                unset($policy['allowed_origins']);
            } else {
                $policy['allowed_origins'] = array_values($allowedOrigins);
            }
        }
        if ($launcher !== null) {
            $cosmetic = Arr::only($launcher, self::LAUNCHER_COSMETIC_KEYS);
            if ($cosmetic === []) {
                unset($policy['launcher']);
            } else {
                $policy['launcher'] = $cosmetic;
            }
        }

        $embed->update(['embed_policy' => $policy]);

        if ($allowedOrigins !== null) {
            EmbedKey::query()
                ->where('embed_id', $embed->getKey())
                ->whereNull('revoked_at')
                ->update(['allowed_origins' => $allowedOrigins === [] ? null : array_values($allowedOrigins)]);
        }
    }

    private function conversationSource(Embed $embed): Conversation
    {
        $source = $embed->source;
        if (! $source instanceof Conversation) {
            throw new InvalidArgumentException('This embed source does not support conversation instructions.');
        }

        return $source;
    }

    private function baseThread(Conversation $source): Conversation
    {
        return Thread::query()->findOrFail($source->getKey());
    }
}
