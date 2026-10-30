<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RetentionPostureChatData extends Data
{
    public function __construct(
        public string $id,
        public ?string $title,
        #[\Spatie\LaravelData\Attributes\MapName('retention_days')]
        public int $retentionDays,
        #[\Spatie\LaravelData\Attributes\MapName('corpus_optin')]
        public bool $corpusOptin,
    ) {}
}
