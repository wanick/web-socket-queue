<?php

namespace Wanick\WebSocketQueue;

class Queue
{
    private $drivers = [];

    // Микросекунд удержания селектора 
    private $maxSelectorAwait = 1000;

    public function registrySocket($driver)
    {
        $this->drivers[] = $driver;

        return $driver;
    }

    public function wait($condition = true)
    {
        $started = microtime(1);
        $i = 0;
        ob_implicit_flush(true);
        ob_end_flush();
        do {
            $last = microtime(1);

            foreach ($this->drivers as $driver) {
                $driver->exec(false);
            }
            $next = is_callable($condition) ? $condition($started) : $condition;
            if ($next) {
                $lost = microtime(1) - $last;
                if ($lost < $this->maxSelectorAwait) {
                    usleep($this->maxSelectorAwait - $lost);
                }
            }
        } while ($next);
    }
}
