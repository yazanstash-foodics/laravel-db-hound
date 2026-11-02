<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('Store and Driver are interfaces')
    ->expect(['Foodics\DbHound\Contracts\Store', 'Foodics\DbHound\Contracts\Driver'])
    ->toBeInterfaces();

arch('Redis is a Store implementation')
    ->expect('Foodics\DbHound\Stores\RedisStore')
    ->toImplement('Foodics\DbHound\Contracts\Store');

arch('MySQL is a Driver implementation')
    ->expect('Foodics\DbHound\Drivers\MySqlDriver')
    ->toImplement('Foodics\DbHound\Contracts\Driver');
