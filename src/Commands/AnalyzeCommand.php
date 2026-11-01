<?php

namespace Foodics\DbHound\Commands;

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Analyzer;
use Foodics\DbHound\Analysis\Suggestion;
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

        $suggestions = [];
        $unparsed = [];

        foreach ($this->byConnection($queries) as $connection => $group) {
            $result = $analyzer->analyze($group, new MySqlDriver($databases->connection($connection)));

            array_push($suggestions, ...$result->suggestions);
            array_push($unparsed, ...$result->unparsed);
        }

        usort($suggestions, fn (Suggestion $a, Suggestion $b) => $b->impact() <=> $a->impact());

        $now = Carbon::now();
        $report = $renderer->render($this->reportContext($now, $queries), new AnalysisResult($suggestions, $unparsed));

        $disk = $filesystems->disk((string) config('db-hound.report.disk', 'local'));
        $directory = trim((string) config('db-hound.report.directory', 'db-hound'), '/');
        $path = $directory.'/report-'.$now->format('Y-m-d_His').'.md';

        $disk->put($path, $report);
        $disk->put($directory.'/latest.md', $report);

        $this->info(count($suggestions).' Suggestion(s). Report written to '.$path.' (and '.$directory.'/latest.md).');

        return self::SUCCESS;
    }

    /**
     * @param  list<QueryAggregate>  $queries
     * @return array<string, list<QueryAggregate>>
     */
    private function byConnection(array $queries): array
    {
        $groups = [];

        foreach ($queries as $query) {
            $groups[$query->connection][] = $query;
        }

        return $groups;
    }

    /**
     * @param  list<QueryAggregate>  $queries
     */
    private function reportContext(Carbon $now, array $queries): ReportContext
    {
        $first = $queries === [] ? null : min(array_map(fn (QueryAggregate $q) => $q->firstSeen, $queries));
        $last = $queries === [] ? null : max(array_map(fn (QueryAggregate $q) => $q->lastSeen, $queries));

        return new ReportContext(
            generatedAt: $now,
            connections: array_keys($this->byConnection($queries)),
            firstSeen: $first === null ? null : Carbon::createFromTimestamp($first, date_default_timezone_get()),
            lastSeen: $last === null ? null : Carbon::createFromTimestamp($last, date_default_timezone_get()),
            fingerprints: count($queries),
            queries: array_sum(array_map(fn (QueryAggregate $q) => $q->count, $queries)),
            thresholds: [],
        );
    }
}
