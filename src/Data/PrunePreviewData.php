<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PrunePreviewData extends Data
{
    public function __construct(
        #[\Spatie\LaravelData\Attributes\MapInputName('would_prune')]
        public int $would_prune,
    ) {}
}
