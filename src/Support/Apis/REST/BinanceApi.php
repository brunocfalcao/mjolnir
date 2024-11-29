<?php

namespace Nidavellir\Mjolnir\Support\Apis\REST;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Nidavellir\Mjolnir\Concerns\HasPropertiesValidation;
use Nidavellir\Mjolnir\Support\ApiClients\REST\BinanceApiClient;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiCredentials;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiRequest;

class BinanceApi
{
    use HasPropertiesValidation;

    // The REST api client.
    protected $client;

    // Initializes CoinMarketCap API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BinanceApiClient([
            'url' => 'https://fapi.binance.com',

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => Crypt::decrypt($credentials->get('api_key')),

            // All ApiCredentials keys need to arrive encrypted.
            'api_secret' => Crypt::decrypt($credentials->get('api_secret')),
        ]);
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Notional-and-Leverage-Brackets
    public function getLeverageBrackets(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/leverageBracket',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Exchange-Information
    public function getExchangeInformation(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/exchangeInfo',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Current-All-Open-Orders
    public function getCurrentOpenOrders(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/openOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/All-Orders
    public function getAllOrders(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/allOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Query-Order
    public function getOrder(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-All-Open-Orders
    public function cancelAllOpenOrders(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'DELETE',
            '/fapi/v1/allOpenOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    public function updateMarginType(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.margintype' => ['required', Rule::in(['ISOLATED', 'CROSSED'])],
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/marginType',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Position-Information-V3
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v3/positionRisk',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Futures-Account-Balance-V3
    public function getAccountBalance()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v3/balance'
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Change-Initial-Leverage
    public function changeInitialLeverage(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.leverage' => 'required|integer',
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/leverage',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Mark-Price
    public function getMarkPrice(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/premiumIndex',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api
    public function placeOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.type' => 'required|string',
            'options.quantity' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Query-Order
    public function queryOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    //https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Modify-Order
    public function modifyOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
            'options.side' => 'required|string',
            'options.quantity' => 'required|string',
            'options.price' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'PUT',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Account-Trade-List
    public function trade(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/userTrades',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Get-Income-History
    public function income(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.incomeType' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/income',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }
}
