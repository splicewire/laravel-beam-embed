<?php

namespace Splicewire\Beam\Embed\Contracts;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Embed\Sources\EmbedSourceResolution;

interface EmbedSourceResolver
{
    public function resolve(Model $source): EmbedSourceResolution;
}
