<?php

namespace Wanick\WebSocketQueue\Drivers;

use Wanick\WebSocketQueue\WebSocket;

abstract class Driver
{
    private static $defaultOptions = [
        'ssl-verify' => false, 
        'socket-connection-timeout' => 10,
    ];
    private $ws = null;
    private $options = [];

    public function __construct($url, $options = [])
    {
        $preparedOptions = array_merge(self::$defaultOptions, $options);
        if ($preparedOptions['ssl-verify'] === false) {
            $context = stream_context_create();
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
    
            $preparedOptions['socket-context'] = $context;
            unset($preparedOptions['ssl-verify']);
        }

        if (!isset($options['logger'])) {
            $options['logger'] = fn() => ([]);
        }

        $this->options = array_merge($preparedOptions, $options);
        $client = new WebSocket($url, $this->options);
        $client->onLoop([$this, 'loop']);
        $client->onMessage([$this, 'message']);

        $this->setClient($client);
        
        $this->options['logger']('debug', ['make driver']);
    }

    /**
     * Add loop function with minimal interval call
     */
    public function onLoop($callback, $microsecond = 1000000)
    {
        $this->ws->onLoop($callback, $microsecond);
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
            
            $this->options['logger']('debug', ['driver', static::class]);
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