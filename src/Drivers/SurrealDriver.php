<?php

namespace Wanick\WebSocketQueue\Drivers;

use Exception;

// https://docs.surrealdb.com/docs/integration/websocket/
class SurrealDriver extends Driver
{
    private $ns = '';
    private $db = '';

    private $version;

    private $counter = 0;
    private $queue = [];

    public function __construct($url, $options = []) {
        parent::__construct($url, $options);

        $this->version(function ($v) {
            $this->version = preg_replace('@.*?(\d+\.\d+\.\d+).*?@', '$1', $v);
        }, true)->exec();
    }

    public function loop()
    {
        foreach ($this->queue as &$task) {
            if ($task['status'] === 'new') {
                $data = $task['data'];
                if ($data['method'] === 'live' && strpos($data['params'][0], 'LIVE') === 0) {
                    // Меняем на query и дальше всё пойдет по логике live запроса
                    $data['method'] = 'query';
                }

                // print(json_encode($data). "\n");
                $this->getWs()->send(1, json_encode($data), 'text', true);
                $task['status'] = $task['blocked'] ? 'await' : 'sent';
            }

            if ($task['status'] === 'await') {
                return true;
            }
        }
        return false;
    }

    public function message($final, $payload, $opcode, $masked)
    {
        if ($opcode === 'ping') {
            $this->getWs()->send(1, "", 'pong', true);
        } else {
            $payload = json_decode($payload, true);
            if (!isset($payload['id'])) {

                // Тут дергаем callbacks по LIVE
                $task = $this->queue[$payload['result']['id']];
                $result = $payload['result'];
                foreach ($task['watchers'] as $callback) {
                    $callback($result['action'], $result['result']);
                }
            } else {
                if (!isset($this->queue[$payload['id']])) {
                    throw new Exception('Что за ошибка не понятно!');
                } else {
                    $task = &$this->queue[$payload['id']];
                    if ($task['status'] !== 'live') $task['status'] = 'done';
                    $callback = $task['callback'];

                    // Вернулся uuid по LIVE запросу для него своя логика
                    if ($task['data']['method'] === 'live') {
                        if (!isset($payload['error'])) {
                            $task['data']['id'] = $payload['result'];
                            $task['watchers'] = [$callback];
                            $task['status'] = 'live';

                            unset($task['callback']);
                            if (is_string($payload['result'])) {
                                $this->queue[$payload['result']] = $task;
                            } else {
                                $this->queue[$payload['result'][0]['result']] = $task;
                            }
                        }

                        $callback(isset($payload['error']) ? 'ERROR' : 'CONNECT', isset($payload['error']) ?  $payload['error'] : $payload['result']);
                    } else if ($callback && is_callable($callback)) {
                        // Обычный результат
                        $callback(@$payload['result'] ?: null, @$payload['error'] ?: null);
                    }
                    unset($this->queue[$payload['id']]);
                }
            }
        }
    }

    /**
     * signin [ ... ]	Signin a root, NS, DB or SC user against SurrealDB
     */
    public function signin(array $auth, $callback = null, $blocked =  true)
    {
        if (!$this->version || !version_compare($this->version, '2.0.0', '>=')) {
            if (!isset($auth['ns'])) {
                $auth['ns'] = $this->ns;
            }
            if (!isset($auth['db'])) {
                $auth['db'] = $this->db;
            }
        }
        return $this->addTask('signin', [$auth], $callback, $blocked);
    }

    /**
     * use [ ns, db ]	Specifies the namespace and database for the current connection
     */
    public function use($ns, $db, $callback = null, $blocked = true)
    {
        $this->ns = $ns;
        $this->db = $db;
        return $this->addTask('use', [$ns, $db], $callback, $blocked);
    }

    /**
     * query [ sql, vars ]	Execute a custom query with optional variables
     */
    public function query(string $sql, $params = null, $callback = null, $blocked = false)
    {
        return $this->addTask('query', [$sql, $params], $callback, $blocked);
    }

    public function liveQuery(string $sql, $params = null, $callback = null, $blocked = false)
    {
        return $this->addTask('live', ['LIVE ' . $sql, $params], $callback, $blocked);
    }

    /**
     * Add Live listener
     */
    public function liveListener($queryUuid, $callback = null)
    {
        if (isset($this->queue[$queryUuid])) {
            $this->queue[$queryUuid]['watchers'][] = $callback;
        }
        return $this;
    }

    /**
     * live [ table, diff ]	Initiate a live query
     */
    public function live(string $table, $diff = null, $callback = null, $blocked = false)
    {
        return $this->addTask('live', [$table, $diff], $callback, $blocked);
    }

    /**
     * let [ name, value ]	Define a variable on the current connection
     */
    public function let($name, $value, $callback = null, $blocked = true)
    {
        return $this->addTask('let', [$name, $value], $callback, $blocked);
    }

    /**
     * unset [ name ]	Remove a variable from the current connection
     */
    public function unset($name, $callback = null, $blocked = true)
    {
        return $this->addTask('unset', [$name], $callback, $blocked);
    }

    /**
     * kill [ queryUuid ]	Kill an active live query
     */
    public function kill($queryUuid, $callback = null, $blocked = false)
    {
        return $this->addTask('kill', [$queryUuid], $callback, $blocked);
    }

    /**
     * authenticate [ token ]	Authenticate a user against SurrealDB with a token
     */
    public function authenticate($token, $callback = null, $blocked = true)
    {
        return $this->addTask('authenticate', [$token], $callback, $blocked);
    }

    /**
     * invalidate	Invalidate a user's session for the current connection
     */
    public function invalidate($callback = null, $blocked = true)
    {
        return $this->addTask('invalidate', null, $callback, $blocked);
    }

    /**
     * info	Returns the record of an authenticated scope user
     */
    public function info($callback = null, $blocked = true)
    {
        return $this->addTask('info', null, $callback, $blocked);
    }

    /**
     * version Returns version information about the database/server
     */
    public function version($callback = null, $blocked = true)
    {
        return $this->addTask('version', null, $callback, $blocked);
    }

    // TODOs
    // signup [ NS, DB, SC, ... ]	Signup a user against a scope's SIGNUP method
    // select [ thing ]	Select either all records in a table or a single record
    // create [ thing, data ]	Create a record with a random or specified ID
    // insert [ thing, data ]	Insert one or multiple records in a table
    // update [ thing, data ]	Replace either all records in a table or a single record with specified data
    // merge [ thing, data ]	Merge specified data into either all records in a table or a single record
    // patch [ thing, patches, diff ]	Patch either all records in a table or a single record with specified patches
    // delete [ thing ]	Delete either all records in a table or a single record

    /**
     * 
     */
    public function addTask($method, $params = null, $callback = null, $blocked = false)
    {
        $id = $this->counter++;
        $this->queue[$id] = [
            'status' => 'new',
            'callback' => $callback ?: fn($r, $e) => !!$e ? print_r([$e]) : null,
            'blocked' => $blocked,
            'data' => [
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]
        ];

        if ($params === null) {
            unset($this->queue[$id]['data']['params']);
        }
        return $this;
    }

    public function getVersion() {
        return $this->version;
    }
}
