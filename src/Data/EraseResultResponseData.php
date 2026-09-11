<?php

namespace Splicewire\Beam\Embed\Data;

use Splicewire\Beam\Data\SuccessResponseData;

class EraseResultResponseData extends SuccessResponseData
{
    public function __construct(public EraseResultData $data) {}
}
