<?php

namespace Foodics\DbHound\Report;

use DateTimeInterface;
use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\FingerprintAccounting;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\RedundantIndex;
use Foodics\DbHound\Analysis\Suggestion;
use Foodics\DbHound\Analysis\UnsuggestedQuery;

/**
 * Renders Suggestions and run context as Markdown with a fixed structure.
 *
 * Only normalized SQL (placeholders, no bindings) and plan metadata (type,
 * key, rows, extras) are rendered; never Sample values or EXPLAIN conditions.
 */
final class ReportRenderer
{
    public function render(ReportContext $context, AnalysisResult $result): string
    {
        $lines = [
            '# db-hound Report',
            '',
            ...$this->warning($context->relaxed),
            ...$this->header($context, $result),
            '',
            ...$this->summary($result->suggestions),
        ];

        $connections = $this->spansConnections($result->suggestions);

        foreach ($this->byTable($result->suggestions) as $suggestions) {
            array_push($lines, ...$this->table($suggestions, $connections));
        }

        array_push($lines, ...$this->unsuggested('Skipped queries', $result->skipped));
        $lines[] = '';
        array_push($lines, ...$this->unsuggested('Unparsed queries', $result->unparsed));
        $lines[] = '';
        array_push($lines, ...$this->redundant($result->redundant));

        return implode("\n", $lines)."\n";
    }

    /**
     * The existing indexes another one on the same table covers, each with
     * that one and the caveats before dropping it; `None.` when empty.
     *
     * @param  list<RedundantIndex>  $redundant
     * @return list<string>
     */
    private function redundant(array $redundant): array
    {
        $lines = ['## Redundant existing indexes', ''];

        if ($redundant === []) {
            $lines[] = 'None.';
        }

        foreach ($redundant as $entry) {
            $old = $entry->redundant;
            $new = $entry->covering;

            $lines[] = "- **{$entry->connection}** — `{$entry->table}`: {$this->indexLabel($old)} is "
                .($entry->exact ? 'an exact duplicate of' : 'a left prefix of')
                ." {$this->indexLabel($new)}, which serves every lookup it does.";

            if ($entry->lostOrder !== null) {
                $key = array_slice($entry->lostOrder, count($old->columns));

                $lines[] = "  - **Primary key order:** with the primary key InnoDB appends, `{$old->name}` also serves ({$this->columns($entry->lostOrder)}) and `{$new->name}` does not: "
                    ."before dropping it, check that no query sorts or seeks on {$this->columns($key)} right after {$this->columns($old->columns)} "
                    .'(e.g. `ORDER BY '.implode(', ', [$old->columns[array_key_last($old->columns)], ...$key]).'`).';
            }

            $lines[] = "  - **Foreign keys:** MySQL needs an index starting with a foreign key's columns. `{$new->name}` starts with every column of `{$old->name}`, "
                ."so it can back any foreign key `{$old->name}` backs, but verify the table's foreign key constraints before dropping it. db-hound never drops an index.";
        }

        return $lines;
    }

    /**
     * A section of flagged Fingerprints that got no Suggestion, each with
     * its connection, why, and its normalized SQL; `None.` when empty.
     *
     * @param  list<UnsuggestedQuery>  $queries
     * @return list<string>
     */
    private function unsuggested(string $heading, array $queries): array
    {
        $lines = ["## {$heading}", ''];

        if ($queries === []) {
            $lines[] = 'None.';
        }

        foreach ($queries as $query) {
            $lines[] = "- **{$query->connection}** — {$query->reason}";
            $lines[] = '';
            $lines[] = '  ```sql';
            $lines[] = '  '.$query->sql;
            $lines[] = '  ```';
        }

        return $lines;
    }

    /**
     * A warning that the thresholds let in noise, naming each relaxed one
     * next to its default, or nothing when none was relaxed.
     *
     * @param  array<string, array{string, string}>  $relaxed  label => [value, default]
     * @return list<string>
     */
    private function warning(array $relaxed): array
    {
        if ($relaxed === []) {
            return [];
        }

        return [
            '> **Warning: relaxed thresholds.** These thresholds are looser than the package defaults:',
            '>',
            ...array_map(
                fn (string $label, array $values) => "> - {$label}: {$values[0]} (default {$values[1]})",
                array_keys($relaxed),
                $relaxed,
            ),
            '>',
            '> Suggestions on small tables are likely noise: MySQL scans a small table whatever its indexes. Rare or fast Fingerprints cost little even unindexed.',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function header(ReportContext $context, AnalysisResult $result): array
    {
        $coverage = $context->firstSeen && $context->lastSeen
            ? $this->time($context->firstSeen).' → '.$this->time($context->lastSeen)
            : 'no captured queries';

        $thresholds = $context->thresholds === []
            ? ['- **Thresholds:** none']
            : ['- **Thresholds:**', ...array_map(
                fn (string $label, string $value) => "  - {$label}: {$value}",
                array_keys($context->thresholds),
                $context->thresholds,
            )];

        return [
            '- **Generated at:** '.$this->time($context->generatedAt),
            '- **Connections:** '.($context->connections === [] ? 'none' : implode(', ', $context->connections)),
            '- **Capture coverage:** '.$coverage,
            "- **Fingerprints analyzed:** {$context->fingerprints} ({$context->queries} captured queries)",
            "- **Fingerprints explained:** {$result->explained}",
            '- **Fingerprint accounting:** '.$this->accounting($result->accounting),
            ...$thresholds,
            '- **Suggestions:** '.count($result->suggestions),
            '- **Skipped queries:** '.count($result->skipped),
            '- **Unparsed queries:** '.count($result->unparsed),
            '- **Redundant existing indexes:** '.count($result->redundant),
        ];
    }

    /**
     * Every analyzed Fingerprint by what became of it, the outcomes none had
     * left out, summed to the analyzed total.
     */
    private function accounting(FingerprintAccounting $accounting): string
    {
        $outcomes = array_filter($accounting->counts());

        if ($outcomes === []) {
            return 'none';
        }

        return implode(' + ', array_map(
            fn (string $outcome, int $fingerprints) => "{$fingerprints} {$outcome}",
            array_keys($outcomes),
            $outcomes,
        )).' = '.$accounting->total().' analyzed';
    }

    /**
     * One row per Suggestion, in rank order.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<string>
     */
    private function summary(array $suggestions): array
    {
        if ($suggestions === []) {
            return ['## Summary', '', 'No Suggestions.', ''];
        }

        $connections = $this->spansConnections($suggestions);

        $lines = [
            '## Summary',
            '',
            '| Rank |'.($connections ? ' Connection |' : '').' Table | Proposed columns | Kind | Reasons | Impact | Fingerprints served |',
            '| --- |'.($connections ? ' --- |' : '').' --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($suggestions as $position => $suggestion) {
            $lines[] = sprintf(
                '| %d |%s `%s` | %s | %s | %s | %s | %d |',
                $position + 1,
                $connections ? " {$suggestion->connection} |" : '',
                $suggestion->table,
                $this->columns($suggestion->columns),
                $suggestion->extends === null ? 'new' : "extend `{$suggestion->extends->name}`",
                implode(', ', $suggestion->reasons),
                number_format($suggestion->impact(), 2),
                count($suggestion->fingerprints),
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * The Kind's warning that dropping the extended index loses the order
     * the primary key InnoDB appends to it gives it, if it does.
     */
    private function lostOrder(Suggestion $suggestion): string
    {
        $held = $suggestion->lostOrder();

        if ($held === null || $suggestion->extends === null) {
            return '';
        }

        $old = $suggestion->extends->columns;
        $key = array_slice($held, count($old));

        return "; with the primary key InnoDB appends, `{$suggestion->extends->name}` also serves ({$this->columns($held)}) and the new index does not: "
            ."before dropping it, check that no query sorts or seeks on {$this->columns($key)} right after {$this->columns($old)} "
            .'(e.g. `ORDER BY '.implode(', ', [end($old), ...$key]).'`)';
    }

    /**
     * The Suggestions grouped by connection and table, each group keyed by
     * rank and in rank order (which is impact order), the groups by their
     * summed impact, highest first (ties keep the order of their best rank).
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<non-empty-array<int, Suggestion>>
     */
    private function byTable(array $suggestions): array
    {
        $groups = [];

        foreach ($suggestions as $position => $suggestion) {
            $groups[$suggestion->connection."\0".$suggestion->table][$position + 1] = $suggestion;
        }

        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b) => $this->impact($b) <=> $this->impact($a));

        return $groups;
    }

    /**
     * Whether the Suggestions are on more than one connection, so that the
     * same table name may be two tables: the summary and the section
     * headings then name each one's connection.
     *
     * @param  list<Suggestion>  $suggestions
     */
    private function spansConnections(array $suggestions): bool
    {
        return count(array_unique(array_map(fn (Suggestion $suggestion) => $suggestion->connection, $suggestions))) > 1;
    }

    /**
     * @param  array<int, Suggestion>  $suggestions
     */
    private function impact(array $suggestions): float
    {
        return array_sum(array_map(fn (Suggestion $suggestion) => $suggestion->impact(), $suggestions));
    }

    /**
     * A table's section: its context once, then each of its Suggestions.
     *
     * The Analyzer looks each table's schema up once per run, so every
     * Suggestion on it carries the same row count and existing indexes.
     *
     * @param  non-empty-array<int, Suggestion>  $suggestions  keyed by rank
     * @param  bool  $connections  whether the heading names the connection
     * @return list<string>
     */
    private function table(array $suggestions, bool $connections): array
    {
        $first = $suggestions[array_key_first($suggestions)];

        $lines = [
            "## `{$first->table}`".($connections ? " ({$first->connection})" : ''),
            '',
            '- **Table:** `'.$first->table.'`',
            '- **Connection:** '.$first->connection,
            '- **Table rows (estimate):** '.number_format($first->rowCount),
            '- **Total impact:** '.number_format($this->impact($suggestions), 2),
            '',
            "**Existing indexes on `{$first->table}`**",
            '',
        ];

        if ($first->existingIndexes === []) {
            $lines[] = '- none';
        }

        foreach ($first->existingIndexes as $index) {
            $lines[] = $this->index($index);
        }

        array_push($lines, '', '**Column types**', '');

        $types = [];
        foreach ($suggestions as $suggestion) {
            foreach ($suggestion->columns as $column) {
                $types[$column] ??= $suggestion->columnTypes[$column] ?? 'unknown';
            }
        }

        foreach ($types as $column => $type) {
            $lines[] = "- `{$column}`: {$type}";
        }

        $lines[] = '';

        foreach ($suggestions as $rank => $suggestion) {
            array_push($lines, ...$this->suggestion($rank, $suggestion));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function suggestion(int $rank, Suggestion $suggestion): array
    {
        $columns = $this->columns($suggestion->columns);
        $extends = $suggestion->extends;

        $lines = [
            "### {$rank}. `{$suggestion->table}` ({$columns})".($extends === null ? '' : " — extends `{$extends->name}`"),
            '',
            '- **Proposed columns (in order):** '.$columns,
            '- **Proposed index name:** `'.$suggestion->indexName().'`',
            '- **Kind:** '.($extends === null
                ? 'new index'
                : "extend existing index `{$extends->name}` ({$this->columns($extends->columns)})"
                    .($suggestion->extendedBecause === null ? '' : ", chosen because it is {$suggestion->extendedBecause->value},")
                    ." to ({$columns}): "
                    ."add this one first, then drop `{$extends->name}` (dropping it first fails if a foreign key needs it, "
                    ."and leaves the table without an index on {$this->columns($extends->columns)} meanwhile)"
                    .$this->lostOrder($suggestion)),
            '- **Reasons:** '.implode(', ', $suggestion->reasons),
            ...array_map(fn (string $note) => '- **Note:** '.$note, $suggestion->notes),
            '',
            '**EXPLAIN summary**',
            '',
            '| type | key | rows | extras |',
            '| --- | --- | --- | --- |',
            sprintf(
                '| %s | %s | %s | %s |',
                $suggestion->access->accessType,
                $suggestion->access->key ?? '—',
                $suggestion->access->rowsExamined === null ? '—' : number_format($suggestion->access->rowsExamined),
                $suggestion->plan->extras() === [] ? '—' : implode(', ', $suggestion->plan->extras()),
            ),
            '',
            '**Impact**',
            '',
            '| count | avg ms | max ms | impact (count × avg ms) |',
            '| --- | --- | --- | --- |',
            sprintf(
                '| %s | %s | %s | %s |',
                number_format($suggestion->count),
                number_format($suggestion->averageMs(), 2),
                number_format($suggestion->maxMs, 2),
                number_format($suggestion->impact(), 2),
            ),
            '',
            '**Served Fingerprints ('.count($suggestion->fingerprints).')**',
            '',
        ];

        foreach ($suggestion->fingerprints as $fingerprint) {
            array_push($lines, '```sql', $fingerprint, '```', '');
        }

        return $lines;
    }

    private function index(Index $index): string
    {
        return '- '.$this->indexLabel($index);
    }

    /**
     * An index as the Report names it: its name and columns, then `unique`
     * when it is unique, its type when it is not a B-tree, and `invisible`.
     */
    private function indexLabel(Index $index): string
    {
        return "`{$index->name}` ({$this->columns($index->columns)})"
            .($index->unique ? ' unique' : '')
            .(strtoupper($index->type) === 'BTREE' ? '' : ' '.strtoupper($index->type))
            .($index->visible ? '' : ' invisible');
    }

    /**
     * @param  list<string>  $columns
     */
    private function columns(array $columns): string
    {
        return implode(', ', array_map(fn (string $column) => "`{$column}`", $columns));
    }

    private function time(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d H:i:s T');
    }
}
