<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\CreatePosition;

use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Abstracts\BaseQueuableJob;
use Nidavellir\Mjolnir\Support\Collections\EligibleExchangeSymbolsForPosition;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\Symbol;

class AssignTokensToPositionsJob extends BaseQueuableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical);
    }

    public function compute()
    {
        $positions = Position::where('status', 'new')
            ->where(
                'account_id',
                $this->account->id
            )->get();

        $tokens = [];

        // Obtain all position tokens so we can remove them from the available exchange symbols.
        $apiPositions = $this->account->apiQueryPositions()->result;

        $dataMapper = new ApiDataMapperProxy($this->account->apiSystem->canonical);

        $exchangeSymbolsToRemove = collect();

        /**
         * Create an ExchangeSymbol collection of all the items that have
         * positions opened on Binance. This is an extra security to avoid
         * having tokens selected from the database that somehow already
         * have a position opened.
         */
        foreach ($apiPositions as $pair => $position) {
            $arrBaseQuote = $dataMapper->identifyBaseAndQuote($pair);

            // Find the right symbol given the base value.
            foreach (Symbol::all() as $symbol) {
                if ($symbol->exchangeCanonical($this->account->apiSystem) == $arrBaseQuote['base']) {
                    $exchangeSymbolsToRemove->push(
                        ExchangeSymbol::where('symbol_id', $symbol->id)
                            ->where('quote_id', $this->account->quote->id)
                            ->first()
                    );
                }
            }
        }

        foreach ($positions as $position) {
            // Do we already have an exchange symbol and a direction?
            if ($position->exchange_symbol_id != null && $position->direction != null) {
                // Start the position creation lifecycle.
                CoreJobQueue::create([
                    'class' => CreatePositionLifecycleJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);

                $tokens[] = $position->parsedTradingPair;
            } else {
                $selectedExchangeSymbol = EligibleExchangeSymbolsForPosition::getBestExchangeSymbol($position, $exchangeSymbolsToRemove);

                if ($selectedExchangeSymbol) {
                    $this->account->load('positions');

                    // Verify if this exchange symbol is already present on active trades for this account.
                    if (in_array($selectedExchangeSymbol->id, $this->account->positions()->active()->pluck('exchange_symbol_id')->toArray())) {
                        $selectedExchangeSymbol->load('symbol');
                        $position->updateToCancelled("This exchange symbol {$selectedExchangeSymbol->symbol->token} ID: {$selectedExchangeSymbol->id} is already selected on other active positions for this account, skipping position");

                        continue;
                    }

                    // info("[AssignTokensToPositionsJob] - Best ExchangeSymbol selected: {$selectedExchangeSymbol->symbol->token}");
                    $data = [];

                    if (! $position->direction) {
                        $data['direction'] = $selectedExchangeSymbol['direction'];
                    }

                    // Copy specific exchange symbol information to the position.
                    $data['exchange_symbol_id'] = $selectedExchangeSymbol->id;
                    $data['indicators'] = $selectedExchangeSymbol->indicators;
                    $data['indicator_timeframe'] = $selectedExchangeSymbol->indicator_timeframe;

                    $position->update($data);

                    $selectedExchangeSymbol->load('symbol');

                    $position->logs()->create([
                        'action_canonical' => 'position-token-assignment',
                        'parameters_array' => $data,
                        'description' => "Token {$selectedExchangeSymbol->symbol->token} assigned to position ID {$position->id}",
                    ]);

                    // Start the position creation lifecycle.
                    CoreJobQueue::create([
                        'class' => CreatePositionLifecycleJob::class,
                        'queue' => 'positions',
                        'arguments' => [
                            'positionId' => $position->id,
                        ],
                    ]);

                    $tokens[] = $position->parsedTradingPair;
                } else {
                    // None available exchange symbols. Fail position.
                    $position->updateToCancelled('No exchange symbols available, try again later');
                }
            }
        }

        return $tokens;
    }
}
