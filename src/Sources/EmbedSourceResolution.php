<?php

namespace Splicewire\Beam\Embed\Sources;

class EmbedSourceResolution
{
    public function __construct(
        public string $displayName,
        public ?string $fixedType,
    ) {}
}
