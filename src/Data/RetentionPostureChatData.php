<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RetentionPostureChatData extends Data
{
    public function __construct(
        public string $id,
        public ?string $title,
        #[\Spatie\LaravelData\Attributes\MapInputName('retention_days')]
        public int $retention_days,
        #[\Spatie\LaravelData\Attributes\MapInputName('corpus_optin')]
        public bool $corpus_optin,
    ) {}
}
