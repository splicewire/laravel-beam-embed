<?php

namespace Splicewire\Beam\Embed\Data;

use Splicewire\Beam\Data\SuccessResponseData;

class PrunePreviewResponseData extends SuccessResponseData
{
    public function __construct(public PrunePreviewData $data) {}
}
