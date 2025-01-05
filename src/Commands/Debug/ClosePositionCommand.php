<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\Position;

class ClosePositionCommand extends Command
{
    protected $signature = 'debug:close-position {position_id}';

    protected $description = 'Cancel all open orders for a specific position id';

    public function handle()
    {
        $position = Position::findOrFail($this->argument('position_id'));

        $apiResponseCancelOrders = $position->apiCancelOrders();
        $apiResponseClose = $position->apiClose();

        dd($apiResponseCancelOrders->result, $apiResponseClose->result);

        return 0;
    }
}
