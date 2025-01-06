<?php

namespace Nidavellir\Mjolnir\Observers;

use Illuminate\Support\Str;
use Nidavellir\Thor\Models\Position;

class PositionApiObserver
{
    public function creating(Position $position): void
    {
        // Assign a UUID before creating the order
        $position->uuid = (string) Str::uuid();
    }
}
