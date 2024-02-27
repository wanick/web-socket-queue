# Web Socket Queue lib

<h3>
    Usage with <a href="https://surrealdb.com#gh-dark-mode-only" target="_blank">
        <img src="https://raw.githubusercontent.com/surrealdb/surrealdb/main/img/white/text.svg" height="15" alt="SurrealDB">
    </a>
</h3>

```php

use Wanick\WebSocketQueue\Drivers\SurrealDriver;

// link to RCP SurrealDB
$surreal = new SurrealDriver('wss://hostname:8080/rcp');

if ($surreal) {
  $surreal->use($config['ns'], $config['db'])
    ->signin([
      "user" => $config['user'],
      "pass" => $config['pass'],
    ], function($resulr, $error) {
        // .... your code 
    })
    ->query('SELECT * FROM people WHERE ago > $ago', ['ago' => 18], function($result, $error) {
        // .... your code
        print_r($result[0]);
    })->exec();
}

```

<h3>
    Usage with <a href="https://nats.io" target="_blank">
        <img src="https://www.gitbook.com/cdn-cgi/image/width=12,dpr=2,height=12,fit=contain,format=auto/https%3A%2F%2F683899388-files.gitbook.io%2F~%2Ffiles%2Fv0%2Fb%2Fgitbook-legacy-files%2Fo%2Fspaces%252F-LqMYcZML1bsXrN3Ezg0%252Favatar.png%3Fgeneration%3D1571848018902627%26alt%3Dmedia" alt="Nats.io"> NATS
    </a>
</h3>

```php
use Wanick\WebSocketQueue\Drivers\NatsDriver;
// link to NATS Connection
$nats = new NatsDriver('wss://nats.server.com:8080/nats');
if ($nats) {
  $nats
    ->pub("EVENT_NAME", ['event' => 'test', 'data' => 123])
    ->pub("EVENT_NAME", ['event' => 'test', 'data' => 234])
    ->exec();
}
```
# Example listening

```php
use Wanick\WebSocketQueue;

$queue = new WebSocketQueue\Queue();
$surreal = new SurrealDriver($url);
// this $surreal use + signin 

$nats = new NatsDriver($url);

$nats->sub('ON-YOUR-CUSTOM-EVENT', null, function(string $result) use($surreal) {
    $data = json_decode($result, true); // if you write in JSON format to NATS
    switch ($data['action']) {
        case 'alert':
            $surreal->query('UPDATE table_name SET field = $value WHERE id = $id', [
                'id' => $data['id'],
                'value' => 1,
            ]);
            // Add "->exec()", if you want saving right now 
            // $queue->wait  execute this query on loop
        break;
    }
});

// can use
// $surreal->live('table_name' ... for all table event

$surreal->liveQuery('SELECT * FROM table_name WHERE field > $max', [ 'max' => 10],
    function ($action, $result) use($nats) {
        switch ($action) {
            case 'UPDATE':
                $nats->pub("ON-YOUR-CUSTOM-EVENT", ['action' => 'alert', 'id' => $result['id']]);
                // can be ->exec()
                break;
            default:
                // no action  CLOSE, CREATE, CONNECT, DELETE
                // use CONNECT - for saving queryUuid  for use liveListener or kill
                break;
        }
    });

$queue->registrySocket($surreal);
$queue->registrySocket($nats);

// Locked loop - and example max work time execute
$queue->wait(fn($s) => (microtime(1) - $s < $max_work_time));
```

