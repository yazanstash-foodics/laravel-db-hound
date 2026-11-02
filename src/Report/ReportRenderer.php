<?php

namespace Foodics\DbHound\Report;

use DateTimeInterface;
use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Suggestion;

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
            ...$this->header($context, $result),
            '',
            '## Suggestions',
            '',
        ];

        if ($result->suggestions === []) {
            $lines[] = 'No Suggestions.';
            $lines[] = '';
        }

        foreach ($result->suggestions as $position => $suggestion) {
            array_push($lines, ...$this->suggestion($position + 1, $suggestion));
        }

        array_push($lines, '## Unparsed queries', '');

        if ($result->unparsed === []) {
            $lines[] = 'None.';
        }

        foreach ($result->unparsed as $query) {
            $lines[] = "- **{$query->connection}** — {$query->reason}";
            $lines[] = '';
            $lines[] = '  ```sql';
            $lines[] = '  '.$query->sql;
            $lines[] = '  ```';
        }

        return implode("\n", $lines)."\n";
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
            ...$thresholds,
            '- **Suggestions:** '.count($result->suggestions),
            '- **Unparsed queries:** '.count($result->unparsed),
        ];
    }

    /**
     * @return list<string>
     */
    private function suggestion(int $position, Suggestion $suggestion): array
    {
        $columns = $this->columns($suggestion->columns);
        $extends = $suggestion->extends;

        $lines = [
            "### {$position}. `{$suggestion->table}` ({$columns})".($extends === null ? '' : " — extends `{$extends->name}`"),
            '',
            '- **Table:** `'.$suggestion->table.'`',
            '- **Connection:** '.$suggestion->connection,
            '- **Table rows (estimate):** '.number_format($suggestion->rowCount),
            '- **Proposed columns (in order):** '.$columns,
            '- **Proposed index name:** `'.$suggestion->indexName().'`',
            '- **Kind:** '.($extends === null
                ? 'new index'
                : "extend existing index `{$extends->name}` ({$this->columns($extends->columns)}) to ({$columns}): "
                    ."add this one first, then drop `{$extends->name}` (dropping it first fails if a foreign key needs it, "
                    ."and leaves the table without an index on {$this->columns($extends->columns)} meanwhile)"),
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
            "**Existing indexes on `{$suggestion->table}`**",
            '',
        ];

        if ($suggestion->existingIndexes === []) {
            $lines[] = '- none';
        }

        foreach ($suggestion->existingIndexes as $index) {
            $lines[] = $this->index($index);
        }

        array_push($lines, '', '**Column types**', '');

        foreach ($suggestion->columns as $column) {
            $lines[] = "- `{$column}`: ".($suggestion->columnTypes[$column] ?? 'unknown');
        }

        array_push(
            $lines,
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
        );

        foreach ($suggestion->fingerprints as $fingerprint) {
            array_push($lines, '```sql', $fingerprint, '```', '');
        }

        return $lines;
    }

    private function index(Index $index): string
    {
        return "- `{$index->name}` ({$this->columns($index->columns)})"
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
