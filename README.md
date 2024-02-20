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
$surrealDB = new SurrealDriver($url);

if ($surrealDB) {
  $surrealDB->use($config['ns'], $config['db'])
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
