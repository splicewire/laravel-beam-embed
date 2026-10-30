<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PrunePreviewData extends Data
{
    public function __construct(
        #[\Spatie\LaravelData\Attributes\MapName('would_prune')]
        public int $wouldPrune,
    ) {}
}
