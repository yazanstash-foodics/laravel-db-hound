<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneCommand extends Command
{
    use SuspendsCapture;

    public $signature = 'db-hound:prune';

    public $description = 'Delete Fingerprints from the db_hound_queries table that were not seen for prune_after_days days';

    public function handle(QueryRepository $repository): int
    {
        $days = (float) config('db-hound.prune_after_days', 30);
        $chunkSize = (int) config('db-hound.prune.chunk_size', 1000);

        // Zero, infinite or NAN days would all prune everything just captured.
        if (! is_finite($days) || $days <= 0) {
            $this->error("db-hound.prune_after_days must be a positive number of days, got {$days}. Nothing was pruned.");

            return self::FAILURE;
        }

        if ($chunkSize < 1) {
            $this->error("db-hound.prune.chunk_size must be at least 1, got {$chunkSize}. Nothing was pruned.");

            return self::FAILURE;
        }

        $cutoff = $this->cutoff($days);

        $pruned = $this->withoutCapture(fn () => $repository->deleteNotSeenSince($cutoff, $chunkSize));

        $this->info("Pruned {$pruned} Fingerprint(s) not seen for {$days} day(s).");

        return self::SUCCESS;
    }

    /**
     * The moment `$days` before now, no earlier than the first instant a
     * DATETIME column holds: nothing stored can be older than that anyway.
     */
    private function cutoff(float $days): int
    {
        $now = Carbon::now()->getTimestamp();
        $earliest = Carbon::create(1000, 1, 1, 0, 0, 0, date_default_timezone_get())->getTimestamp();
        $seconds = $days * 86400;

        return $seconds >= $now - $earliest ? $earliest : $now - (int) round($seconds);
    }
}
