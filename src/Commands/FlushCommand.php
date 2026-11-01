<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Console\Command;
use Throwable;

class FlushCommand extends Command
{
    use SuspendsCapture;

    public $signature = 'db-hound:flush';

    public $description = 'Move captured query aggregates from the Store into the db_hound_queries table';

    public function handle(Store $store, QueryRepository $repository): int
    {
        $aggregates = $this->withoutCapture(function () use ($store, $repository) {
            $aggregates = $store->snapshot();

            try {
                $repository->merge($aggregates);
            } catch (Throwable $e) {
                // The snapshot already left the Store and the merge rolled
                // back: hand it back so the next flush persists it.
                $store->push($aggregates);

                throw $e;
            }

            return $aggregates;
        });

        $this->info('Flushed '.count($aggregates).' Fingerprint(s).');

        return self::SUCCESS;
    }
}
