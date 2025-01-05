<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Position;

use GuzzleHttp\Psr7\Response;
use Nidavellir\Mjolnir\Support\Proxies\ApiDataMapperProxy;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiResponse;
use Nidavellir\Thor\Models\Order;

trait HasApiFeatures
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        return $this->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    // Cancels all open orders (but not the position if it's open).
    public function apiCancelOrders(): ApiResponse
    {
        // Construct compatible trading pair for Exchange.
        $symbol = get_base_token_for_exchange($this->exchangeSymbol->symbol->token, $this->account->apiSystem->canonical);
        $parsedSymbol = $this->apiMapper($this->account->apiSystem->canonical)->baseWithQuote($this->exchangeSymbol->symbol->token, $this->account->quote->canonical);

        $this->apiProperties = $this->apiMapper()->prepareCancelOrdersProperties($parsedSymbol);

        $this->apiResponse = $this->apiAccount()->withApi()->cancelAllOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveCancelOrdersResponse($this->apiResponse)
        );
    }

    // Closes the position (opens a contrary order compared to the position).
    public function apiClose()
    {
        // Get all positions for this position account.
        $apiResponse = $this->account->apiQueryPositions();

        // Get sanitized positions, key = pair.
        $positions = $apiResponse->result;

        // Construct compatible trading pair for Exchange.
        $symbol = get_base_token_for_exchange($this->exchangeSymbol->symbol->token, $this->account->apiSystem->canonical);
        $parsedSymbol = $this->apiMapper()->baseWithQuote($this->exchangeSymbol->symbol->token, $this->account->quote->canonical);

        if (array_key_exists($parsedSymbol, $positions)) {
            $this->changeToSyncing();

            // We have a position. Lets place a contrary order to close it.
            $positionFromExchange = $positions[$parsedSymbol];

            $data = [
                'type' => 'MARKET-CANCEL',
            ];

            // Side is the contrary of the current position side (we check the amount).
            if ($positionFromExchange['positionAmt'] < 0) {
                $data['side'] = $this->apiMapper()->sideType('BUY');
            } else {
                $data['side'] = $this->apiMapper()->sideType('SELL');
            }

            $data['quantity'] = abs($positionFromExchange['positionAmt']);
            $data['position_id'] = $this->id;

            $order = Order::create($data);
            $apiResponse = $order->apiPlace();
            $order->apiSync();

            $this->update(['closed_at' => now()]);

            $this->changeToSynced();

            return $apiResponse;
        }

        return new ApiResponse(new Response, []);
    }
}
