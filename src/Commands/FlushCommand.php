<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Contracts\Store;
use Foodics\DbHound\Flush\FlushThresholds;
use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class FlushCommand extends Command
{
    use SuspendsCapture;

    public $signature = 'db-hound:flush
        {--force : Flush whatever is buffered, even below every threshold}';

    public $description = 'Move captured query aggregates from the Store into the db_hound_queries table once a size or age threshold is reached';

    public function handle(Store $store, QueryRepository $repository): int
    {
        $flushed = $this->withoutCapture(function () use ($store, $repository) {
            if (! $this->option('force')) {
                $stats = $store->stats();
                $thresholds = FlushThresholds::fromConfig((array) config('db-hound.flush', []));

                if (! $thresholds->reachedBy($stats, Carbon::now()->getTimestamp())) {
                    $this->info("Nothing to flush yet: {$stats->fingerprints} Fingerprint(s), {$stats->bytes} byte(s) buffered, below every threshold.");

                    return null;
                }
            }

            $aggregates = $store->snapshot();

            try {
                $repository->merge($aggregates);
            } catch (Throwable $e) {
                // The snapshot already left the Store and the merge rolled
                // back: hand it back so the next flush persists it.
                $store->push($aggregates);

                throw $e;
            }

            return count($aggregates);
        });

        if ($flushed !== null) {
            $this->info("Flushed {$flushed} Fingerprint(s).");
        }

        return self::SUCCESS;
    }
}
