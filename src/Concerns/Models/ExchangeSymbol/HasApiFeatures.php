<?php

namespace Nidavellir\Mjolnir\Concerns\Models\ExchangeSymbol;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper($canonical)
    {
        return new ApiDataMapperProxy($canonical);
    }

    public function apiQueryMarkPrice(Account $account)
    {
        $symbol = get_base_token_for_exchange($this->symbol->token, $account->apiSystem->canonical);
        $parsedSymbol = $this->apiMapper($account->apiSystem->canonical)->baseWithQuote($this->symbol->token, $account->quote->canonical);

        $this->apiProperties = $this->apiMapper($account->apiSystem->canonical)->prepareQueryMarkPriceProperties($parsedSymbol);
        $this->apiResponse = $account->withApi()->getMarkPrice($this->apiProperties);

        return $this->apiMapper($account->apiSystem->canonical)->resolveQueryMarkPriceResponse($this->apiResponse);
    }
}
