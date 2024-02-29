<?php

namespace Wanick\WebSocketQueue;

use Exception;

class WebSocket
{
    private static $opcodes = [
        'continuation' => 0,
        'text'         => 1,
        'binary'       => 2,
        'close'        => 8,
        'ping'         => 9,
        'pong'         => 10,
    ];

    private $socket = null;
    private $listeners = [];
    private $loops = [];
    private $connecting = [];

    private $options = [];

    public function __construct($url, $options = [])
    {
        $options['url'] = $url;

        if (!isset($options['logger'])) {
            $options['logger'] = fn() => ([]);
        }
        $this->options = $options;
    }

    /**
     * @return resource
     */
    public function getSocket()
    {
        if (!$this->socket) {
            $this->start();
        }
        return $this->socket;
    }

    public function readPacket()
    {
        [$final, $payload, $opcode, $masked] = $this->pullFrame();

        foreach ($this->listeners as $listener) {
            call_user_func_array($listener, [$final, $payload, $opcode, $masked]);
        }
    }


    public function onMessage($callback)
    {
        $this->listeners[] = $callback;
    }
    public function onLoop($callback, $millisecond = 1000)
    {
        $this->loops[] = [
            'last' => 0,
            'interval' => $millisecond,
            'callback' => $callback
        ];
    }

    public function onConnect($callback)
    {
        $this->connecting[] = $callback;
    }

    public function loop()
    {
        foreach ($this->loops as &$loop) {
            if ($loop['last']) {
                $lost = (microtime(1) - $loop['last']) * 1000000;
                if ($lost < $loop['interval']) {
                    continue;
                }
            }
            call_user_func_array($loop['callback'], []);
            $loop['last'] = microtime(1);
        }
    }

    protected function read(string $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));
            if (!$buffer) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    $message = 'Client read timeout';
                    throw new Exception($message);
                }
            }
            if ($buffer === false || strcmp($buffer, '') == 0) {
                $code = socket_last_error($this->socket);
                socket_clear_error($this->socket);
    
                if ($code == SOCKET_EAGAIN) {
                    // Nothing to read from non-blocking socket, try again later...
                } else {
                    $read = strlen($data);
                    throw new Exception("Broken frame, read {$read} of stated {$length} bytes.");
                }
            }
            $data .= $buffer;
            $read = strlen($data);
        }
        return $data;
    }

    public function start()
    {
        $flags = STREAM_CLIENT_CONNECT;
        $url = $this->options['url'];

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);

        $host = parse_url($url, PHP_URL_HOST);


        $port = $port ? $port : ($scheme == 'wss' ? 443 : 80);
        $scheme = $scheme == 'wss' ? 'ssl' : 'tcp';
        $address = $scheme . '://' . $host . ':' . $port;

        $this->socket = stream_socket_client($address, $errorNumber, $errorString, $this->options['socket-connection-timeout'], $flags, isset($this->options['socket-context']) ? $this->options['socket-context'] : null);

        if (!$this->socket) {
            throw new Exception("{$errorString} ({$errorNumber})");
        } else {
            stream_set_blocking($this->socket, false);
            $path = parse_url($url, PHP_URL_PATH);
            $this->connect($host, $path);

            foreach ($this->connecting as $connect) {
                call_user_func_array($connect, []);
            }
        }

        return $this;
    }

    private function close()
    {
        fclose($this->socket);
    }

    public function connect($host, $path)
    {
        $key = self::generateKey();
        $headers = [
            'Host'                  => $host,
            'User-Agent'            => 'websocket client php',
            'Connection'            => 'Upgrade',
            'Upgrade'               => 'websocket',
            'Sec-WebSocket-Key'     => $key,
            'Sec-WebSocket-Version' => '13',
        ];
        $header = "GET {$path} HTTP/1.1\r\n" . implode(
            "\r\n",
            array_map(
                function ($key, $value) {
                    return "$key: $value";
                },
                array_keys($headers),
                $headers
            )
        ) . "\r\n\r\n";

        $this->options['logger']('debug', ['send http request connect']);
        fwrite($this->socket, $header);

        // Get server response header (terminated with double CR+LF).
        $response = '';
        try {
            do {
                $buffer = fread($this->socket, 1024);
                $response .= $buffer;
            } while (substr_count($response, "\r\n\r\n") == 0);
        } catch (Exception $e) {
            throw new Exception('Client handshake error', $e->getCode(), call_user_func([$e, 'getData']), $e);
        }

        // Validate response.
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $error = sprintf(
                "Connection to '%s' failed: Server sent invalid upgrade response: %s",
                (string) $host . '' . $path,
                (string) $response
            );
            throw new Exception($error);
        }

        $keyAccept = trim($matches[1]);
        $expectedResonse = base64_encode(
            pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
        );

        if ($keyAccept !== $expectedResonse) {
            $error = 'Server sent bad upgrade response.';
            throw new Exception($error);
        }
    }

    public function send($final, $payload, $opcode, $masked)
    {
        // TODO Add logger for driver
        $this->pushFrame([
            $final, $payload, $opcode, $masked
        ]);
    }

    protected static function generateKey(): string
    {
        $key = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= chr(rand(33, 126));
        }
        return base64_encode($key);
    }

    private function pullFrame(): array
    {
        // Read the fragment "header" first, two bytes.
        $data = $this->read(2);
        list($byte_1, $byte_2) = array_values(unpack('C*', $data));
        $final = (bool)($byte_1 & 0b10000000); // Final fragment marker.

        $rsv = $byte_1 & 0b01110000; // Unused bits, ignore
        // Parse opcode
        $opcode_int = $byte_1 & 0b00001111;
        $opcode_ints = array_flip(self::$opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
            $warning = "Bad opcode in websocket frame: {$opcode_int}";
            throw new Exception($warning);
        }
        $opcode = $opcode_ints[$opcode_int];

        // Masking bit
        $masked = (bool)($byte_2 & 0b10000000);

        $payload = '';

        // Payload length
        $payload_length = $byte_2 & 0b01111111;

        if ($payload_length > 125) {
            if ($payload_length === 126) {
                $data = $this->read(2); // 126: Payload is a 16-bit unsigned int
                $payload_length = current(unpack('n', $data));
            } else {
                $data = $this->read(8); // 127: Payload is a 64-bit unsigned int
                $payload_length = current(unpack('J', $data));
            }
        }

        // Get masking key.
        if ($masked) {
            $masking_key = $this->read(4);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
            $data = $this->read($payload_length);

            if ($masked) {
                // Unmask payload.
                for ($i = 0; $i < $payload_length; $i++) {
                    $payload .= ($data[$i] ^ $masking_key[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }
        return [$final, $payload, $opcode, $masked];
    }

    // Push frame to stream
    private function pushFrame(array $frame): void
    {
        list($final, $payload, $opcode, $masked) = $frame;
        $data = '';
        $byte_1 = $final ? 0b10000000 : 0b00000000; // Final fragment marker.
        $byte_1 |= self::$opcodes[$opcode]; // Set opcode.
        $data .= pack('C', $byte_1);

        $byte_2 = $masked ? 0b10000000 : 0b00000000; // Masking bit marker.

        // 7 bits of payload length...
        $payload_length = strlen($payload);
        if ($payload_length > 65535) {
            $data .= pack('C', $byte_2 | 0b01111111);
            $data .= pack('J', $payload_length);
        } elseif ($payload_length > 125) {
            $data .= pack('C', $byte_2 | 0b01111110);
            $data .= pack('n', $payload_length);
        } else {
            $data .= pack('C', $byte_2 | $payload_length);
        }

        // Handle masking
        if ($masked) {
            // generate a random mask:
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(rand(0, 255));
            }
            $data .= $mask;

            // Append payload to frame:
            for ($i = 0; $i < $payload_length; $i++) {
                $data .= $payload[$i] ^ $mask[$i % 4];
            }
        } else {
            $data .= $payload;
        }

        $this->write($data);
    }

    public function write(string $data): void
    {
        // TODO add write buffer size
        $length = strlen($data);
        $written = fwrite($this->socket, $data);

        if ($written === false) {
            throw new Exception("Failed to write {$length} bytes.");
        }
        if ($written < strlen($data)) {
            throw new Exception("Could only write {$written} out of {$length} bytes.");
        }
    }
}
