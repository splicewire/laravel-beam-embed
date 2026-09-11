<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RetentionPostureData extends Data
{
    /** @param list<RetentionPostureChatData> $chats */
    public function __construct(
        #[\Spatie\LaravelData\Attributes\MapInputName('default_days')]
        public int $default_days,
        #[DataCollectionOf(RetentionPostureChatData::class)]
        public array $chats,
    ) {}
}
