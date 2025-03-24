<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Indicator;

abstract class BaseIndicator
{
    // An id passed to the api, will be returned also this id for result ident.
    public string $id;

    // The api endpoint value (e.g: adx).
    public string $endpoint;

    // The full parameters payload to be sent to the api call.
    public array $parameters = [];

    // The returned result after the api call.
    protected array $data = [];

    // Not mandatory in case we are having a grouped indicator query.
    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(ExchangeSymbol $exchangeSymbol, array $parameters = [])
    {
        $this->exchangeSymbol = $exchangeSymbol;

        // Get the child class name
        $childClass = get_called_class();

        // Query the Indicator model for the parameters of the corresponding class
        $indicator = Indicator::where('class', $childClass)->first();

        // Retrieve parameters (already cast to array)
        $indicatorParams = $indicator?->parameters ?? [];

        // Merge provided parameters with those from the database (constructor parameters take priority)
        $mergedParams = array_merge($indicatorParams, $parameters);

        // Check if "interval" key is there.
        if (! array_key_exists('interval', $mergedParams)) {
            throw new \Exception('Indicator misses key -- interval --');
        }

        // Add "addResultTimestamp" key.
        $mergedParams['addResultTimestamp'] = true;

        // Apply merged parameters
        foreach ($mergedParams as $key => $value) {
            $this->addParameter($key, $value);
        }
    }

    // Returns the data that was fetched from the taapi api.
    public function data()
    {
        return $this->data;
    }

    final public function compute()
    {
        /**
         * Make the API call.
         * Return result(), made by the client.
         */
        $this->apiQuery();

        // Add timestamp for humans.
        if (is_array($this->data['timestamp'])) {
            $this->data['timestamp_for_humans'] = array_map(function ($ts) {
                return date('Y-m-d H:i:s', (int) $ts);
            }, $this->data['timestamp']);
        } else {
            $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
        }

        return $this->data;
    }

    // Adds a new parameter into the parameters array
    public function addParameter(string $key, $value)
    {
        $this->parameters[$key] = $value;
    }

    // Queries taapi and returns result.
    public function apiQuery()
    {
        $apiAccount = Account::admin('taapi');

        if (! $this->exchangeSymbol) {
            throw new \Exception('No exchange symbol defined for the indicator query');
        }

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        $this->parameters['endpoint'] = $this->endpoint;

        $apiProperties = $apiDataMapper->prepareQueryIndicatorProperties($this->exchangeSymbol, $this->parameters);

        $this->data = $apiDataMapper->resolveQueryIndicatorResponse($apiAccount->withApi()->getIndicatorValues($apiProperties));
    }

    // Loads previously fetched indicator data into the indicator.
    public function load(array $data)
    {
        $this->data = $data;
    }

    /**
     * Should return:
     * -> A number, e.g.: 68
     * -> A boolean: true, false
     * -> A direction: LONG, SHORT
     * -> null
     */
    abstract public function conclusion();

    protected function addTimestampForHumans()
    {
        if (is_array($this->data['timestamp'])) {
            $this->data['timestamp_for_humans'] = array_map(function ($ts) {
                return date('Y-m-d H:i:s', (int) $ts);
            }, $this->data['timestamp']);
        } else {
            $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
        }
    }
}
