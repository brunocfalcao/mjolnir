<?php

namespace Nidavellir\Mjolnir\Jobs\Processes\DataRefresh;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Abstracts\BaseApiableJob;
use Nidavellir\Mjolnir\Abstracts\BaseExceptionHandler;
use Nidavellir\Mjolnir\Support\Proxies\ApiRESTProxy;
use Nidavellir\Mjolnir\Support\Proxies\RateLimitProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Symbol;

class SyncAllSymbolsJob extends BaseApiableJob
{
    public ApiRESTProxy $api;

    public function __construct()
    {
        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')->withAccount(Account::admin('coinmarketcap'));
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap');
    }

    public function computeApiable()
    {
        $this->api = new ApiRESTProxy('coinmarketcap', new ApiCredentials(Account::admin('coinmarketcap')->credentials));

        $response = $this->api->getSymbolsMetadata($this->prepareApiProperties());
        $this->coreJobQueue->update(['response' => $this->parseResponse($response)]);

        foreach ($this->parseResponse($response) as $symbol) {
            $this->updateOrCreateSymbolMetadata($symbol);
        }

        return $response;
    }

    protected function prepareApiProperties(): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.id', implode(
            ',',
            Symbol::all()->pluck('cmc_id')->toArray()
        ));

        return $properties;
    }

    protected function parseResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
    }

    protected function updateOrCreateSymbolMetadata(array $symbol): void
    {
        Symbol::updateOrCreate(
            ['cmc_id' => $symbol['id']],
            [
                'name' => $symbol['name'],
                'token' => $symbol['symbol'],
                'website' => $this->sanitizeWebsiteAttribute($symbol['urls']['website']),
                'category' => $symbol['category'],
                'description' => $symbol['description'],
                'image_url' => $symbol['logo'],
            ]
        );
    }

    protected function sanitizeWebsiteAttribute(mixed $website): string
    {
        return is_array($website) ? collect($website)->first() : $website;
    }
}
