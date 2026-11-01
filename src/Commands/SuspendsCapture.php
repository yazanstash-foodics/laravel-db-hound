<?php

namespace Foodics\DbHound\Commands;

/**
 * db-hound's own commands must not capture the queries they run themselves
 * (Store reads, upserts, EXPLAIN, information_schema lookups).
 */
trait SuspendsCapture
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withoutCapture(callable $callback): mixed
    {
        $enabled = config('db-hound.enabled');
        config()->set('db-hound.enabled', false);

        try {
            return $callback();
        } finally {
            config()->set('db-hound.enabled', $enabled);
        }
    }
}
