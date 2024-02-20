# Web Socket Queue lib

<h3>
    Usage with <a href="https://surrealdb.com#gh-dark-mode-only" target="_blank">
        <img src="https://raw.githubusercontent.com/surrealdb/surrealdb/main/img/white/text.svg" height="15" alt="SurrealDB">
    </a>
</h3>

```php

use Wanick\WebSocketQueue\Drivers\SurrealDriver;

// link to RCP SurrealDB
$url = 'wss://hostname:8080/rcp';
$surreal = new SurrealDriver($url);

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
$url = 'wss://nats.server.com:8080/nats';
$nats = new NatsDriver($url);

if ($nats) {
  $nats
    ->pub("EVENT_NAME", ['event' => 'test', 'data' => 123])
    ->pub("EVENT_NAME", ['event' => 'test', 'data' => 234])
    ->exec();
}
```

# TODO example  for PHP -> NATS -> SurrealDB
