<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Abstracts\BaseApiExceptionHandler;
use App\Collections\ExchangeSymbols\ExchangeSymbolsUpsertable;
use App\Concerns\Traceable;
use App\Models\Account;
use App\Models\ApiSystem;
use App\Support\Proxies\ApiDataMapperProxy;
use App\Support\Proxies\ApiProxy;
use App\Support\Proxies\RateLimitProxy;
use App\ValueObjects\ApiCredentials;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Jobs\GateKeepers\ApiCallJob;

class UpsertLeverageAndNotionalBracketsJob extends ApiCallJob
{
    use Traceable;

    public $result;

    public ApiSystem $apiSystem;

    public ApiDataMapperProxy $dataMapper;

    public Account $account;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::find($apiSystemId);

        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)
            ->withAccount(Account::admin($this->apiSystem->canonical));

        $this->exceptionHandler = BaseApiExceptionHandler::make('binance');

        $this->dataMapper = new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    // Executes an API call to retrieve leverage and notional brackets and returns the response.
    public function compute()
    {
        $api = new ApiProxy(
            $this->apiSystem->canonical,
            new ApiCredentials(
                $this->rateLimiter->account->credentials
            )
        );

        $properties = $this->dataMapper->prepareLeverageAndNotionalBracketsProperties();

        $response = $this->call(function () use ($api, $properties) {
            return $api->getLeverageBrackets($properties);
        });

        if (! $response) {
            return;
        }

        $this->result = json_decode($response->getBody(), true);

        $mappedResult = $this->dataMapper
            ->resolveLeverageAndNotionalBracketsProperties($this->result);

        // Collection of leverage brackets, indexed by symbol for quick access
        $leverageBrackets = collect($mappedResult)->keyBy('symbol');

        // Retrieve exchange symbols for the given API system to avoid N+1 issues
        $exchangeSymbols = new ExchangeSymbolsUpsertable($this->apiSystem);

        // Perform upserts within a single transaction for efficiency
        DB::transaction(function () use ($exchangeSymbols, $leverageBrackets) {
            foreach ($exchangeSymbols as $exchangeSymbol) {
                // Resolve symbol name from data mapper for consistency
                $symbolName = $this->dataMapper->baseWithQuote(
                    $exchangeSymbol->symbol->token,
                    $exchangeSymbol->quote->canonical
                );

                // Check if leverage data exists for the symbol
                $bracketData = $leverageBrackets->get($symbolName);

                // Update exchange symbol with new leverage and notional bracket data
                if ($bracketData && $exchangeSymbol->leverage_brackets != $bracketData) {
                    $exchangeSymbol->lockForUpdate();
                    $exchangeSymbol->update([
                        'leverage_brackets' => $bracketData,
                    ]);
                }
            }
        });
    }
}
