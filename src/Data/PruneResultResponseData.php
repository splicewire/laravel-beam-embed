<?php

namespace Splicewire\Beam\Embed\Data;

use Splicewire\Beam\Data\SuccessResponseData;

class PruneResultResponseData extends SuccessResponseData
{
    public function __construct(public PruneResultData $data) {}
}
