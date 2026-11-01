<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Capture\QueryAggregate;
use Foodics\DbHound\Drivers\MySqlDriver;
use Foodics\DbHound\Persistence\QueryRepository;
use Foodics\DbHound\Report\ReportContext;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class AnalyzeCommand extends Command
{
    use SuspendsCapture;

    public $signature = 'db-hound:analyze';

    public $description = 'EXPLAIN captured queries and write a Markdown Report of missing-index Suggestions';

    public function handle(
        QueryRepository $repository,
        Analyzer $analyzer,
        ReportRenderer $renderer,
        DatabaseManager $databases,
        Filesystems $filesystems,
    ): int {
        return $this->withoutCapture(fn () => $this->analyze($repository, $analyzer, $renderer, $databases, $filesystems));
    }

    private function analyze(
        QueryRepository $repository,
        Analyzer $analyzer,
        ReportRenderer $renderer,
        DatabaseManager $databases,
        Filesystems $filesystems,
    ): int {
        $queries = $repository->all();

        $result = $analyzer->analyze($queries, fn (string $connection) => new MySqlDriver(
            $databases->connection($this->analysisConnection($connection)),
            (int) config('db-hound.analysis.max_execution_time_ms', 5000),
        ));

        $now = Carbon::now();
        $report = $renderer->render($this->reportContext($now, $queries, $analyzer->qualification->describe()), $result);

        $disk = $filesystems->disk((string) config('db-hound.report.disk', 'local'));
        $directory = trim((string) config('db-hound.report.directory', 'db-hound'), '/');
        $path = $directory.'/report-'.$now->format('Y-m-d_His').'.md';

        $disk->put($path, $report);
        $disk->put($directory.'/latest.md', $report);

        $this->info(count($result->suggestions).' Suggestion(s). Report written to '.$path.' (and '.$directory.'/latest.md).');

        return self::SUCCESS;
    }

    /**
     * The connection a captured connection's Fingerprints are explained and
     * its schema inspected on: its `db-hound.analysis.connections` mapping
     * (a read replica, say), or itself.
     */
    private function analysisConnection(string $connection): string
    {
        $mapped = ((array) config('db-hound.analysis.connections', []))[$connection] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : $connection;
    }

    /**
     * @param  list<QueryAggregate>  $queries
     * @param  array<string, string>  $thresholds
     */
    private function reportContext(Carbon $now, array $queries, array $thresholds): ReportContext
    {
        $first = $queries === [] ? null : min(array_map(fn (QueryAggregate $q) => $q->firstSeen, $queries));
        $last = $queries === [] ? null : max(array_map(fn (QueryAggregate $q) => $q->lastSeen, $queries));

        return new ReportContext(
            generatedAt: $now,
            connections: array_map(
                fn (string $connection) => $this->analysisConnection($connection) === $connection
                    ? $connection
                    : $connection.' (analyzed on '.$this->analysisConnection($connection).')',
                array_values(array_unique(array_map(fn (QueryAggregate $q) => $q->connection, $queries))),
            ),
            firstSeen: $first === null ? null : Carbon::createFromTimestamp($first, date_default_timezone_get()),
            lastSeen: $last === null ? null : Carbon::createFromTimestamp($last, date_default_timezone_get()),
            fingerprints: count($queries),
            queries: array_sum(array_map(fn (QueryAggregate $q) => $q->count, $queries)),
            thresholds: $thresholds,
        );
    }
}
