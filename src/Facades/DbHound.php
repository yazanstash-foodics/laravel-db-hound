<?php

namespace Foodics\DbHound\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Foodics\DbHound\DbHound
 */
class DbHound extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Foodics\DbHound\DbHound::class;
    }
}
