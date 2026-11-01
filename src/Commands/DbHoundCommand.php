<?php

namespace Foodics\DbHound\Commands;

use Illuminate\Console\Command;

class DbHoundCommand extends Command
{
    public $signature = 'db-hound';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
