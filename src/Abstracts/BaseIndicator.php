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
    protected array $result = [];

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

    /**
     * Default overridable method by the child class, should return:
     *
     * a value e.g.: 67
     * a direction e.g.: LONG, SHORT
     * a boolean e.g.: true, false
     */
    public function result()
    {
        return $this->result;
    }

    final public function compute()
    {
        /**
         * Make the API call.
         * Return result(), made by the client.
         */
        $result = $this->apiQuery();

        // Ensure 'timestamp' exists and is an array
        if (array_key_exists('timestamp', $result) && is_array($result['timestamp']) && ! empty($result['timestamp'])) {
            $result['timestamp_for_humans'] = array_map(fn ($ts) => date('Y-m-d H:i:s', (int) $ts), $result['timestamp']);
        }

        return $result;
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

        return $apiDataMapper->resolveQueryIndicatorResponse($apiAccount->withApi()->getIndicatorValues($apiProperties));
    }
}
