<?php

namespace Nidavellir\Mjolnir\Jobs\Cronjobs;

use App\Abstracts\BaseApiExceptionHandler;
use App\Collections\TradingPairs\TradingPairs;
use App\Models\Account;
use App\Models\Symbol;
use App\Support\Proxies\ApiProxy;
use App\Support\Proxies\RateLimitProxy;
use App\ValueObjects\ApiCredentials;
use App\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Nidavellir\Mjolnir\Jobs\GateKeepers\ApiCallJob;

class UpsertSymbolsMetadataJob extends ApiCallJob
{
    public ApiCredentials $credentials;

    public function __construct()
    {
        $adminAccount = Account::admin('coinmarketcap');

        $this->rateLimiter = RateLimitProxy::make('coinmarketcap')
            ->withAccount($adminAccount);

        $this->exceptionHandler = BaseApiExceptionHandler::make('coinmarketcap');

        $this->credentials = new ApiCredentials(
            $adminAccount->credentials
        );
    }

    public function compute()
    {
        $apiProxy = new ApiProxy('coinmarketcap', $this->credentials);

        $properties = $this->prepareApiProperties();

        $response = $this->call(function () use ($apiProxy, $properties) {
            return $apiProxy->getSymbolsMetadata($properties);
        });

        if (! $response) {
            return;
        }

        $symbolsData = $this->parseResponse($response);

        // Batch upsert for efficient database operations
        DB::transaction(function () use ($symbolsData) {
            foreach ($symbolsData as $symbol) {
                $this->updateOrCreateSymbolMetadata($symbol);
            }
        });
    }

    protected function prepareApiProperties(): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('options.id', implode(
            ',',
            (new TradingPairs)->pluck('cmc_id')->toArray()
        ));

        return $properties;
    }

    protected function parseResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
    }

    protected function updateOrCreateSymbolMetadata(array $symbol): void
    {
        DB::transaction(function () use ($symbol) {
            $existingSymbol = Symbol::where('cmc_id', $symbol['id'])->exists();

            if (! $existingSymbol) {
                // Notify if the symbol is new
                $this->notify($symbol);
            }

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
        });
    }

    protected function sanitizeWebsiteAttribute(mixed $website): string
    {
        return is_array($website) ? collect($website)->first() : $website;
    }

    protected function notify(array $symbol): void
    {
        notify(
            title: "{$symbol['symbol']} added to Nidavellir",
            application: 'nidavellir_cronjobs'
        );
    }
}
