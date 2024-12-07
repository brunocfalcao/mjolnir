<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\Hourly;

use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Jobs\Apiable\Symbol\QuerySymbolMetadataJob;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\Symbol;
use Nidavellir\Thor\Models\TradingPair;

class ImportSymbolsJob extends BaseQueuableJob
{
    public ?TradingPair $tradingPair;

    public function compute()
    {
        // All core job queues need to have index = 1.
        $blockUuid = CoreJobQueue::newUuid();

        foreach (TradingPair::all() as $tradingPair) {
            $symbol = Symbol::firstOrCreate([
                'cmc_id' => $tradingPair->cmc_id,
            ], [
                'token' => $tradingPair->token,
            ]);

            CoreJobQueue::create([
                'class' => QuerySymbolMetadataJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'symbolId' => $symbol->id,
                ],
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);
        }
    }
}
