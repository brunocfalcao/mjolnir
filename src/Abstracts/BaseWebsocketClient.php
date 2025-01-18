<?php

namespace Nidavellir\Mjolnir\Abstracts;

use Exception;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;

abstract class BaseWebsocketClient
{
    protected string $baseURL;

    protected ?Connector $wsConnector;

    protected ?WebSocket $wsConnection = null;

    protected LoopInterface $loop;

    protected int $reconnectAttempt = 0;

    protected int $maxReconnectAttempts = 5;

    public function __construct(array $args = [])
    {
        $this->baseURL = $args['baseURL'] ?? '';
        $this->loop = LoopFactory::create();
        $this->wsConnector = new Connector($this->loop);
    }

    public function ping(): void
    {
        if ($this->wsConnection) {
            $this->wsConnection->send(new Frame('', true, Frame::OP_PING));
        } else {
            echo "Ping can't be sent before WebSocket connection is established.".PHP_EOL;
        }
    }

    protected function handleCallback(string $url, array $callback): void
    {
        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;

                // Handle ping/pong
                $conn->on('ping', function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $this->loop->addPeriodicTimer(900, function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                // Set up message and other event handlers
                if (is_callable($callback)) {
                    $conn->on('message', function ($msg) use ($conn, $callback) {
                        $callback($conn, $msg);
                    });
                }

                if (is_array($callback)) {
                    foreach ($callback as $event => $func) {
                        $event = strtolower((string) $event);
                        if (in_array($event, ['message', 'ping', 'pong', 'close'])) {
                            $conn->on($event, function ($msg) use ($conn, $func) {
                                call_user_func($func, $conn, $msg);
                            });
                        }
                    }
                }

                $conn->on('close', function () use ($url, $callback) {
                    $this->reconnect($url, $callback);
                });
            },
            function ($e) use ($url, $callback) {
                echo "Could not connect: {$e->getMessage()}".PHP_EOL;
                $this->reconnect($url, $callback);
            }
        );

        $this->loop->run();
    }

    private function createWSConnection(string $url)
    {
        return ($this->wsConnector)($url);
    }

    private function reconnect(string $url, array $callback): void
    {
        if ($this->reconnectAttempt >= $this->maxReconnectAttempts) {
            throw new Exception('Max reconnect attempts reached. Connection closed.');
        }

        $delay = pow(2, $this->reconnectAttempt);
        echo "Reconnecting in {$delay} seconds...".PHP_EOL;

        $this->loop->addTimer($delay, function () use ($url, $callback) {
            $this->reconnectAttempt++;
            $this->handleCallback($url, $callback);
        });
    }
}
