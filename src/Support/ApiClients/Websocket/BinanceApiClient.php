<?php

namespace Nidavellir\Mjolnir\Support\ApiClients\Websocket;

use Nidavellir\Mjolnir\Abstracts\BaseWebsocketClient;

class BinanceApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://fstream.binance.com',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        // Reset message count every second to adhere to rate limit
        $this->loop->addPeriodicTimer($this->rateLimitInterval, function () {
            $this->messageCount = 0;
        });
    }

    public function subscribeToStream(string $streamName, array $callbacks): void
    {
        if ($this->messageCount >= 10) {
            echo 'Rate limit exceeded. Message skipped.'.PHP_EOL;

            return;
        }

        $url = $this->baseURL."/ws/{$streamName}";
        $this->messageCount++;
        $this->handleCallback($url, $callbacks);
    }
}
