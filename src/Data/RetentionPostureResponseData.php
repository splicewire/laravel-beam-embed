<?php

namespace Splicewire\Beam\Embed\Data;

use Splicewire\Beam\Data\SuccessResponseData;

class RetentionPostureResponseData extends SuccessResponseData
{
    public function __construct(public RetentionPostureData $data) {}
}
