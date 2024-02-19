<?php

namespace Wanick\WebSocketQueue\Drivers;

use Wanick\WebSocketQueue\WebSocket;

abstract class Driver
{
    private static $defaultOptions = [
        'ssl-verify' => false, 
    ];
    private $ws = null;

    public function __construct($url, $options = [])
    {
        $computedOption = array_merge(self::$defaultOptions, $options);

        $preparedOptions = [];
        if ($computedOption['ssl-verify'] === false) {
            $context = stream_context_create();
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
    
            $preparedOptions['context'] = $context;
        }

        $client = new WebSocket($url, array_merge($preparedOptions, $options));
        $client->onLoop([$this, 'loop']);
        $client->onMessage([$this, 'message']);

        $this->setClient($client);
    }

    /**
     * Add loop function with minimal interval call
     */
    public function onLoop($callback, $millisecond = 1000000)
    {
        $this->ws->onLoop($callback, $millisecond);
        return $this;
    }

    /**
     * Executor, send queue package 
     */
    public function exec($blocked = true)
    {
        $socket = $this->ws->getSocket();
        do {
            $read = [$socket];
            @stream_select($read, $write, $except, 0, 0);
            if (!empty($read)) {
                $this->ws->readPacket();
            }
            !$blocked && $this->ws->loop();
        } while ($this->loop() && $blocked);
    }

    public function getWs(): WebSocket
    {
        return $this->ws;
    }

    public function setClient(WebSocket $client)
    {
        $this->ws = $client;
    }
    
    abstract public function loop();
}