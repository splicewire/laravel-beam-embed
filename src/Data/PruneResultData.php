<?php

namespace Splicewire\Beam\Embed\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PruneResultData extends Data
{
    public function __construct(
        public int $pruned,
    ) {}
}
