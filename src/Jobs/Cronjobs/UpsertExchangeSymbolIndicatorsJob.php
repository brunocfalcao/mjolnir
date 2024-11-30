<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Collections\ExchangeSymbols\ExchangeSymbolsUpsertable;
use App\Models\ExchangeSymbol;
use App\Models\JobQueue;
use App\Models\TradeConfiguration;
use Illuminate\Database\Eloquent\Collection;
use Nidavellir\Mjolnir\Jobs\GateKeepers\NonApiCallJob;

class UpsertExchangeSymbolIndicatorsJob extends NonApiCallJob
{
    public function compute()
    {
        $timeFrame = TradeConfiguration::active()->first()->indicator_timeframes[0];

        // Testing purposes.
        $collection = (new ExchangeSymbolsUpsertable);
        //$exchangeSymbol = ExchangeSymbol::first();
        //$eloquentCollection = new Collection([$exchangeSymbol]);

        //(new ExchangeSymbolsUpsertable)
        //$eloquentCollection
        (new ExchangeSymbolsUpsertable)->each(function ($exchangeSymbol) use ($timeFrame) {
            JobQueue::add(
                jobClass: UpsertExchangeSymbolIndicatorsAndSideJob::class,
                arguments: ['exchangeSymbolId' => $exchangeSymbol->id, 'timeFrame' => $timeFrame],
                queueName: 'indicators'
            );
        });

        JobQueue::dispatch();
    }
}
