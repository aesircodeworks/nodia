<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Data\CapabilityListData;

class CapabilityController
{
    public function __invoke(): CapabilityListData
    {
        return CapabilityListData::fromRegistry();
    }
}
