<?php

namespace Wanick\WebSocketQueue\Drivers;

// https://docs.nats.io/reference/reference-protocols/nats-protocol#protocol-messages
class NatsDriver extends Driver
{
    private $counter = 0;
    private $queue = [];

    private $latest = 0;
    const CRLF = "\r\n";

    public function __construct($url, $options = [])
    {
        parent::__construct($url, $options);
    }

    public function loop()
    {
        foreach ($this->queue as $i => &$task) {
            if ($task['status'] === 'new') {
                // print('->>' . $task['data']. "\n");
                $this->getWs()->send(1, $task['data']. self::CRLF, 'binary', true);
                $task['status'] = 'sent';
            }

            if ($task['method'] !== 'SUB') {
                unset($this->queue[$i]);
            }
        }
        $this->autoPing(30);
    }

    protected function autoPing($interval)
    {
        if (time() - $this->latest > $interval) {
            $this->ping();
        }
    }


    public function message($final, $payload, $opcode, $masked)
    {
        $list = explode(self::CRLF, $payload);
        $messages = [];
        while(!empty($list))
        {
            $line = array_shift($list);

            $message = [];
            @[$message['action'], $body] = explode(" ", $line, 2);
            if (empty($message['action'])) continue;
            if (in_array($message['action'], ['+OK', 'PING', 'PONG'])) {
                // no body
            }
            if (in_array($message['action'], ['-ERR'])) {
                $message['error'] = $body;
            }
            if (in_array($message['action'], ['INFO'])) {
                $message['body'] = $body;
            }
            if (in_array($message['action'], ['MSG'])) {
                @[$subject, $sid, $replyTo, $bytes] = explode(' ', $body);
                if (is_null($bytes)) {
                    $bytes = $replyTo;
                    $replyTo = null;
                }
                $msg = null;
                if ($bytes > 0) {
                    $msg = '';
                    do {
                        $next = array_shift($list);
                        $msg .= $next;
                    } while (strlen($msg) < $bytes);
                }
                $message['subject'] = $subject;
                $message['sid'] = $sid;
                $message['replyTo'] = $replyTo;
                $message['bytes'] = $bytes;
                $message['body'] = $msg;
            }

            // $body = null;

            $messages[] = $message;
        }
        
        foreach ($messages as $message) {
            // print('<<-' . $message['action'] . "\n");
            switch ($message['action']) {
                case 'INFO':
                    $options['connect'] = json_decode($body, true);
                    break;
                case 'PING':
                    // print("<<-PING\n");
                    $this->pong();
                    break;
                case 'PONG':
                    // print("<<-PONG\n");
                    break;
                case '+OK':
                    // TODO тут ответ на SUB
                    break;
                case '-ERR':
                    // TODO тут какая то ошибка
                    break;
                case 'MSG':
                    if (isset($this->queue[$message['subject'].$message['sid']])) {
                        $task = $this->queue[$message['subject'].$message['sid']];
    
                        $callback = $task['callback'];
                        if ($callback && is_callable($callback))
                        {
                            $callback($message['body']);
                        } 
                    } else {
                        print_r(['listener not found', $message]);
                    }
                    break;
                default:
                    print_r(['Event not found', $message]);
                    break;
            }
        }
    }

    public function sub($name, $params = null, $callback = null)
    {
        return $this->addTask('SUB', $name, $params, $callback);
    }

    public function pub($name, $params = null)
    {
        return $this->addTask('PUB', $name, $params);
    }

    public function ping(): void
    {
        // print("->>PING\n");
        $this->getWs()->send(1, "PING".self::CRLF, 'binary', true);
        $this->latest = time();
    }

    public function pong(): void
    {
        // print("->>PONG\n");
        $this->getWs()->send(1, "PONG".self::CRLF, 'binary', true);
    }

    /**
     * 
     */
    public function addTask($method, $name, $params = null, $callback = null)
    {
        $id = $this->counter++;
        $data = ' ' . $id;
        if ($params) {
            $data = ' ' . mb_strlen($params) . "\r\n" . $params;
        }

        $this->queue[$name.$id] = [
            'status' => 'new',
            'callback' => $callback,
            'data' => $method. ' ' . $name . $data,
            'method' => $method,
        ];

        // ␍␊
        return $this;
    }
}