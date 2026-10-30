<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RetentionPostureData extends Data
{
    /** @param list<RetentionPostureChatData> $chats */
    public function __construct(
        #[\Spatie\LaravelData\Attributes\MapName('default_days')]
        public int $defaultDays,
        #[DataCollectionOf(RetentionPostureChatData::class)]
        public array $chats,
    ) {}
}
