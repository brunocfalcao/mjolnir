<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Abstracts\BaseApiExceptionHandler;
use App\Collections\Symbols\Symbols;
use App\Concerns\Traceable;
use App\Models\Account;
use App\Models\ApiSystem;
use App\Models\ExchangeSymbol;
use App\Models\Quote;
use App\Models\Symbol;
use App\Support\Proxies\ApiDataMapperProxy;
use App\Support\Proxies\ApiProxy;
use App\Support\Proxies\RateLimitProxy;
use App\ValueObjects\ApiCredentials;
use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Jobs\GateKeepers\ApiCallJob;

/**
 * UpsertExchangeInformationJob retrieves exchange symbol information
 * from the API and upserts it into the database for an exchange.
 */
class UpsertExchangeInformationJob extends ApiCallJob
{
    use Traceable;

    // Stores the API response result.
    public $result;

    // Proxy for mapping API data.
    public ApiDataMapperProxy $dataMapper;

    public ApiSystem $apiSystem;

    // Constructor to initialize the job with the required repositories and account.
    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::find($apiSystemId);

        $adminAccount = Account::admin($this->apiSystem->canonical);

        $this->dataMapper = new ApiDataMapperProxy($this->apiSystem->canonical);

        $this->rateLimiter = RateLimitProxy::make($this->apiSystem->canonical)
            ->withAccount($adminAccount);

        $this->exceptionHandler = BaseApiExceptionHandler::make($this->apiSystem->canonical);
    }

    // Executes an API call to the exchange and returns the response.
    public function compute()
    {
        $properties = $this->dataMapper->prepareExchangeInformationProperties();

        $apiProxy = new ApiProxy(
            $this->apiSystem->canonical,
            new ApiCredentials(
                $this->rateLimiter->account->credentials
            )
        );

        $response = $this->call(function () use ($apiProxy, $properties) {
            return $apiProxy->getExchangeInformation($properties);
        });

        if (! $response) {
            return;
        }

        // Decode the response body.
        $this->result = json_decode($response->getBody(), true);

        // Map the result using the dataMapper.
        $mappedResult = $this->dataMapper
            ->resolveExchangeInformationResponse($this->result);

        // Create a collection of the symbols from the result, indexed by symbol.
        $exchangeSymbolsResult = collect($mappedResult)->keyBy('symbol')->toArray();

        // Get the eligible symbols from the repository.
        $symbolsToUpdate = (new Symbols)->pluck('token');

        /**
         * We will create exchange symbols for both USDT and USDC, the ones
         * that exist and that are part of our Symbols
         */
        foreach (new Symbols as $symbol) {
            $exchangeInformationEntries = $this->filterByKeyPrefix($exchangeSymbolsResult, $symbol->token);

            foreach ($exchangeInformationEntries as $tradingPair => $data) {
                try {
                    $baseWithQuote = $this->dataMapper->identifyBaseAndQuote($tradingPair);
                } catch (\Exception $e) {
                    // In case we get a wrong base or quote. No issue.
                    continue;
                }

                $base = Symbol::firstWhere('token', $baseWithQuote[0]);
                $quote = Quote::firstWhere('canonical', $baseWithQuote[1]);

                if ($base && $quote) {

                    /**
                     * Last check for now: Min notional. BUG!
                     */
                    if ((int) $data['minNotional'] < 20) {
                        ExchangeSymbol::updateOrCreate(
                            [
                                'symbol_id' => $base->id,
                                'quote_id' => $quote->id,
                                'api_system_id' => $this->apiSystem->id,
                            ],
                            [
                                'price_precision' => $data['pricePrecision'],
                                'quantity_precision' => $data['quantityPrecision'],
                                'min_notional' => $data['minNotional'],
                                'tick_size' => $data['tickSize'],
                                'symbol_information' => $data,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function filterByKeyPrefix(array $data, string $prefix): array
    {
        return array_filter($data, fn ($value, $key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_BOTH);
    }
}
