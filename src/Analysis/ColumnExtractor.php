<?php

namespace Foodics\DbHound\Analysis;

use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\GroupKeyword;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokenType;

/**
 * Finds, per table reference of a SELECT, the columns it is filtered (WHERE),
 * joined (JOIN ON / USING) and sorted (ORDER BY / GROUP BY) on, classified as
 * equality, range or sort. Aliases resolve to their base table; subqueries in
 * FROM (derived tables), the SELECT list, `EXISTS (...)`, `IN (...)`,
 * comparisons and HAVING are followed, with correlated columns attributed to
 * the outer table they name, and a column joined to a derived table's to
 * that table.
 *
 * phpmyadmin/sql-parser provides the statement structure; predicates are read
 * from its tokens, splitting only top-level ANDs (anything under an OR is not
 * indexable as a composite).
 */
final class ColumnExtractor
{
    private const EQUALITY = ['=', '<=>'];

    private const RANGE = ['<', '>', '<=', '>='];

    /** The units of `INTERVAL <value> <unit>`. */
    private const INTERVAL_UNITS = [
        'MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR',
        'SECOND_MICROSECOND', 'MINUTE_MICROSECOND', 'MINUTE_SECOND', 'HOUR_MICROSECOND', 'HOUR_SECOND',
        'HOUR_MINUTE', 'DAY_MICROSECOND', 'DAY_SECOND', 'DAY_MINUTE', 'DAY_HOUR', 'YEAR_MONTH',
    ];

    /** @var list<TableColumns> */
    private array $references = [];

    private bool $unresolved = false;

    /** @var list<string> the aliases of the derived tables (FROM subqueries) */
    private array $derived = [];

    /** @var list<string> the aliases of the derived tables whose query is being read, outermost first */
    private array $within = [];

    /** whether a subquery in the SELECT list is being read */
    private bool $selectList = false;

    /** @var callable(string): list<string> */
    private $columnsOf;

    /** @var array<string, array<string, string>> lowercased column name => as the schema spells it, per table */
    private array $columns = [];

    /**
     * @param  callable(string): list<string>  $columnsOf  a base table's column names, to place
     *                                                     unqualified columns when a query reads several tables
     *
     * @throws UnparseableSql
     */
    public function extract(string $sql, callable $columnsOf): ExtractedColumns
    {
        $this->references = [];
        $this->unresolved = false;
        $this->derived = [];
        $this->within = [];
        $this->selectList = false;
        $this->columnsOf = $columnsOf;
        $this->columns = [];

        $statement = $this->parse($sql);

        $this->select($statement, [], []);

        foreach ($statement->union as $union) {
            $part = $this->unionPart($union);

            if ($part !== null) {
                $this->select($part, [], []);
            }
        }

        return new ExtractedColumns($this->references, $this->unresolved, $this->derived);
    }

    /**
     * The parser stores each UNION part as a [keyword, statement] pair.
     */
    private function unionPart(mixed $union): ?SelectStatement
    {
        $part = is_array($union) ? ($union[1] ?? null) : $union;

        return $part instanceof SelectStatement ? $part : null;
    }

    /**
     * @throws UnparseableSql
     */
    private function parse(string $sql): SelectStatement
    {
        $parser = new Parser($sql);

        if ($parser->errors !== []) {
            throw new UnparseableSql('the SQL parser could not parse it ('.$parser->errors[0]->getMessage().')');
        }

        if (count($parser->statements) !== 1 || ! $parser->statements[0] instanceof SelectStatement) {
            throw new UnparseableSql('the SQL parser did not read it as a single SELECT');
        }

        return $parser->statements[0];
    }

    /**
     * @param  list<array<string, ?TableColumns>>  $outer  enclosing scopes, innermost first
     * @param  list<string>  $within  the aliases of the derived tables whose query this is, outermost first
     */
    private function select(SelectStatement $select, array $outer, array $within): void
    {
        $enclosing = $this->within;
        $this->within = $within;

        try {
            $this->readSelect($select, $outer);
        } finally {
            $this->within = $enclosing;
        }
    }

    /**
     * @param  list<array<string, ?TableColumns>>  $outer  enclosing scopes, innermost first
     */
    private function readSelect(SelectStatement $select, array $outer): void
    {
        /** @var array<string, ?TableColumns> $scope */
        $scope = [];

        foreach ($select->from as $expression) {
            $this->addReference($scope, $expression, $outer);
        }

        $using = [];

        foreach ($select->join ?? [] as $join) {
            if ($join->expr === null) {
                continue;
            }

            $previous = $scope;
            $joined = $this->addReference($scope, $join->expr, $outer);

            foreach ($join->using->values ?? [] as $column) {
                $using[] = [$joined, $previous, $column];
            }
        }

        $chain = [$scope, ...$outer];

        foreach ($select->join ?? [] as $join) {
            $this->conditions($join->on, $chain);
        }

        $this->conditions($select->where, $chain);

        // HAVING filters groups, not rows: only its subqueries (EXPLAIN's `having_subqueries`) filter their own tables.
        if ($select->having !== null && $select->having !== []) {
            [$tokens, $raw] = $this->tokens(implode(' ', array_map(fn (Condition $condition) => $condition->expr, $select->having)));
            $this->withSubqueries($tokens, $raw, $chain);
        }

        // A correlated subquery in the SELECT list (withCount, withSum), alone or within an expression (CASE, COALESCE), filters its own tables.
        foreach ($select->expr as $item) {
            $sql = $item instanceof Expression ? (string) $item->expr : (string) $item;

            if (stripos($sql, 'select') !== false) {
                [$tokens, $raw] = $this->tokens($sql);
                $enclosing = $this->selectList;
                $this->selectList = true;

                try {
                    $this->withSubqueries($tokens, $raw, $chain);
                } finally {
                    $this->selectList = $enclosing;
                }
            }
        }

        foreach ($using as [$joined, $previous, $column]) {
            $other = $this->resolve(null, $column, [$previous]);

            if ($joined !== null && $other !== null) {
                $this->join($joined, $column, $other, $column);
            }
        }

        $this->sort($select, $scope);
    }

    /**
     * @param  array<string, ?TableColumns>  $scope
     * @param  list<array<string, ?TableColumns>>  $outer
     */
    private function addReference(array &$scope, Expression $expression, array $outer): ?TableColumns
    {
        if ($expression->subquery !== null) {
            $alias = (string) $expression->alias;
            $this->select($this->parse($this->unwrap((string) $expression->expr)), $outer, [...$this->within, $alias]);
            $scope[$alias] = null;
            $this->derived[] = $alias;

            return null;
        }

        if ($expression->table === null || $expression->table === '') {
            $scope[(string) $expression->alias] = null;

            return null;
        }

        $reference = new TableColumns(
            $expression->alias ?? $expression->table,
            $expression->table,
            $expression->database === null || $expression->database === '' ? null : $expression->database,
            $this->within,
            $this->selectList,
        );
        $this->references[] = $reference;
        $scope[$reference->name] = $reference;

        return $reference;
    }

    /**
     * @param  list<Condition>|null  $conditions
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function conditions(?array $conditions, array $chain): void
    {
        if ($conditions === null || $conditions === []) {
            return;
        }

        $text = implode(' ', array_map(fn (Condition $condition) => $condition->expr, $conditions));
        [$tokens, $raw] = $this->tokens($text);

        $this->conjunction($tokens, $raw, $chain);
    }

    /**
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @param  list<string>  $raw
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function conjunction(array $tokens, array $raw, array $chain): void
    {
        $parts = [[]];
        $depth = 0;
        $between = false;
        $disjunction = false;

        foreach ($tokens as $token) {
            if ($token['kind'] === 'open') {
                $depth++;
            } elseif ($token['kind'] === 'close') {
                $depth--;
            }

            if ($depth === 0 && $token['kind'] === 'keyword' && $token['value'] === 'BETWEEN') {
                $between = true;
            } elseif ($depth === 0 && $this->isAnd($token)) {
                if ($between) {
                    $between = false;
                } else {
                    $parts[] = [];

                    continue;
                }
            } elseif ($depth === 0 && $this->isOr($token)) {
                $disjunction = true;
            }

            $parts[array_key_last($parts)][] = $token;
        }

        if ($disjunction) {
            // AND binds tighter than OR: the whole expression is one disjunction.
            $this->unindexable($this->withSubqueries($tokens, $raw, $chain), $chain);

            return;
        }

        foreach ($parts as $part) {
            $this->predicate($part, $raw, $chain);
        }
    }

    /**
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @param  list<string>  $raw
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function predicate(array $tokens, array $raw, array $chain): void
    {
        if ($tokens === []) {
            return;
        }

        if ($tokens[0]['kind'] === 'open' && $this->closing($tokens, 0) === count($tokens) - 1
            && ($tokens[1]['value'] ?? null) !== 'SELECT') {
            $this->conjunction(array_slice($tokens, 1, -1), $raw, $chain);

            return;
        }

        $tokens = $this->withSubqueries($tokens, $raw, $chain);

        $left = $this->columnAt($tokens, 0);

        if ($left !== null && $this->classify($left, array_slice($tokens, $left['next']), $chain)) {
            return;
        }

        $right = $this->trailingColumn($tokens);

        if ($right !== null && $this->classifyReversed($right, array_slice($tokens, 0, $right['start']), $chain)) {
            return;
        }

        $this->unindexable($tokens, $chain);
    }

    /**
     * `column <op> operand`.
     *
     * @param  array{qualifier: ?string, column: string, next: int}  $column
     * @param  list<array{kind: string, value: string, at: int}>  $rest
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function classify(array $column, array $rest, array $chain): bool
    {
        $operator = $rest[0] ?? null;

        if ($operator === null) {
            return false;
        }

        $operand = array_slice($rest, 1);
        $value = $operator['value'];

        if ($operator['kind'] === 'operator' && in_array($value, self::EQUALITY, true)) {
            $other = $this->columnAt($operand, 0);

            if ($other !== null && $other['next'] === count($operand)) {
                return $this->joinColumns($column, $other, $chain);
            }

            return $this->constant($operand) && $this->equality($column, $chain);
        }

        if ($operator['kind'] === 'operator' && in_array($value, self::RANGE, true)) {
            return $this->constant($operand) && $this->range($column, $chain);
        }

        // IN and EXISTS lex as function names before a parenthesis.
        if (! in_array($operator['kind'], ['keyword', 'function'], true)) {
            return false;
        }

        return match ($value) {
            'IN' => ($operand[0]['kind'] ?? null) === 'open' && $this->closing($operand, 0) === count($operand) - 1
                && $this->constant(array_slice($operand, 1, -1)) && $this->inList($column, array_slice($operand, 1, -1), $chain),
            'IS' => count($operand) === 1 && in_array($operand[0]['value'], ['NULL', 'NOT NULL'], true)
                && $this->nullCheck($column, $operand[0]['value'] === 'NULL', $chain),
            'BETWEEN', 'LIKE' => $this->constant(array_values(array_filter($operand, fn (array $token) => ! $this->isAnd($token))))
                && $this->range($column, $chain),
            default => false,
        };
    }

    /**
     * `operand <op> column`.
     *
     * @param  array{qualifier: ?string, column: string, start: int}  $column
     * @param  list<array{kind: string, value: string, at: int}>  $before
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function classifyReversed(array $column, array $before, array $chain): bool
    {
        $operator = array_pop($before);

        if ($operator === null || $operator['kind'] !== 'operator' || ! $this->constant($before)) {
            return false;
        }

        $located = ['qualifier' => $column['qualifier'], 'column' => $column['column'], 'next' => 0];

        return match (true) {
            in_array($operator['value'], self::EQUALITY, true) => $this->equality($located, $chain),
            in_array($operator['value'], self::RANGE, true) => $this->range($located, $chain),
            default => false,
        };
    }

    /**
     * @param  array{qualifier: ?string, column: string, next: int}  $column
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function equality(array $column, array $chain): bool
    {
        $reference = $this->resolve($column['qualifier'], $column['column'], $chain);
        $reference?->addEquality($this->spelled($reference, $column['column']));

        return true;
    }

    /**
     * `column IN (values)`: one value is an equality, several a list.
     *
     * @param  array{qualifier: ?string, column: string, next: int}  $column
     * @param  list<array{kind: string, value: string, at: int}>  $values
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function inList(array $column, array $values, array $chain): bool
    {
        $depth = 0;
        $several = false;

        foreach ($values as $token) {
            $depth += match ($token['kind']) {
                'open' => 1,
                'close' => -1,
                default => 0,
            };
            $several = $several || ($depth === 0 && $token['kind'] === 'comma');
        }

        if (! $several) {
            return $this->equality($column, $chain);
        }

        $reference = $this->resolve($column['qualifier'], $column['column'], $chain);
        $reference?->addList($this->spelled($reference, $column['column']));

        return true;
    }

    /**
     * @param  array{qualifier: ?string, column: string, next: int}  $column
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function range(array $column, array $chain): bool
    {
        $reference = $this->resolve($column['qualifier'], $column['column'], $chain);
        $reference?->addRange($this->spelled($reference, $column['column']));

        return true;
    }

    /**
     * `column IS NULL` (`$null`) or `column IS NOT NULL`.
     *
     * @param  array{qualifier: ?string, column: string, next: int}  $column
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function nullCheck(array $column, bool $null, array $chain): bool
    {
        $reference = $this->resolve($column['qualifier'], $column['column'], $chain);

        if ($reference === null) {
            return true;
        }

        if ($null) {
            $reference->addNull($this->spelled($reference, $column['column']));
        } else {
            $reference->addNotNull($this->spelled($reference, $column['column']));
        }

        return true;
    }

    /**
     * @param  array{qualifier: ?string, column: string, next: int}  $left
     * @param  array{qualifier: ?string, column: string, next: int}  $right
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function joinColumns(array $left, array $right, array $chain): bool
    {
        $leftTable = $this->resolve($left['qualifier'], $left['column'], $chain);
        $rightTable = $this->resolve($right['qualifier'], $right['column'], $chain);

        if ($leftTable === null || $rightTable === null) {
            // A column joined to a derived table's narrows its table once the plan has read the derived table.
            $derived = $this->derivedTable($leftTable === null ? $left['qualifier'] : $right['qualifier'], $chain);
            $table = $leftTable ?? $rightTable;

            if ($table !== null && $derived !== null) {
                $table->addEquality($this->spelled($table, $leftTable === null ? $right['column'] : $left['column']), $derived);
            }

            return true;
        }

        if ($leftTable === $rightTable) {
            return false;
        }

        $this->join($leftTable, $left['column'], $rightTable, $right['column']);

        return true;
    }

    /**
     * The derived table `$qualifier` names, searching scopes innermost first.
     *
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function derivedTable(?string $qualifier, array $chain): ?string
    {
        foreach ($chain as $scope) {
            if ($qualifier !== null && array_key_exists($qualifier, $scope)) {
                return $scope[$qualifier] === null && in_array($qualifier, $this->derived, true) ? $qualifier : null;
            }
        }

        return null;
    }

    private function join(TableColumns $left, string $leftColumn, TableColumns $right, string $rightColumn): void
    {
        $left->addEquality($this->spelled($left, $leftColumn), $right->name);
        $right->addEquality($this->spelled($right, $rightColumn), $left->name);
    }

    /**
     * Mark every table a non-indexable predicate reads.
     *
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @param  list<array<string, ?TableColumns>>  $chain
     */
    private function unindexable(array $tokens, array $chain): void
    {
        for ($i = 0; $i < count($tokens); $i++) {
            $column = $this->columnAt($tokens, $i);

            if ($column === null) {
                continue;
            }

            $reference = $this->resolve($column['qualifier'], $column['column'], $chain);

            if ($reference !== null) {
                $reference->hasUnindexablePredicates = true;
            }

            $i = $column['next'] - 1;
        }
    }

    /**
     * Extract every `(SELECT ...)` in the predicate as its own scope and
     * replace it with a single opaque value.
     *
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @param  list<string>  $raw
     * @param  list<array<string, ?TableColumns>>  $chain
     * @return list<array{kind: string, value: string, at: int}>
     */
    private function withSubqueries(array $tokens, array $raw, array $chain): array
    {
        $result = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]['kind'] === 'open' && ($tokens[$i + 1]['value'] ?? null) === 'SELECT') {
                $close = $this->closing($tokens, $i);
                $sql = implode('', array_slice($raw, $tokens[$i]['at'] + 1, $tokens[$close]['at'] - $tokens[$i]['at'] - 1));

                $this->select($this->parse($sql), $chain, $this->within);

                $result[] = ['kind' => 'subquery', 'value' => '', 'at' => $tokens[$i]['at']];
                $i = $close;

                continue;
            }

            $result[] = $tokens[$i];
        }

        // EXISTS (subquery) filters nothing in this scope.
        $words = array_map(fn (array $token) => $token['value'], $result);

        if ($words === ['EXISTS', ''] || $words === ['NOT', 'EXISTS', '']) {
            return [];
        }

        return $result;
    }

    /**
     * @param  array<string, ?TableColumns>  $scope
     */
    private function sort(SelectStatement $select, array $scope): void
    {
        $order = $this->sortColumns($select->order ?? [], $scope);

        if ($select->group !== null && $select->group !== []) {
            $group = $this->sortColumns($select->group, $scope);

            // An index reads groups in its order: it serves an ORDER BY only of the leading GROUP BY columns.
            if ($group === null || ($select->order ?? []) !== [] && ($order === null || $order[0] !== $group[0]
                || array_slice($group[1], 0, count($order[1])) !== $order[1])) {
                return;
            }

            [$owner, $columns] = $group;
            $clause = 'GROUP BY';
        } elseif ($order !== null) {
            [$owner, $columns] = $order;
            $clause = 'ORDER BY';
        } else {
            return;
        }

        $owner->sort = $columns;
        $owner->sortClause = $clause;
    }

    /**
     * The table reference that owns every GROUP BY / ORDER BY item, and its
     * columns. Null when an item is no plain column of one reference, or
     * when the columns no equality pins are sorted in mixed directions (an
     * ascending index serves one direction, read forward or backward).
     *
     * @param  array<GroupKeyword|OrderKeyword>  $items
     * @param  array<string, ?TableColumns>  $scope
     * @return array{TableColumns, list<string>}|null
     */
    private function sortColumns(array $items, array $scope): ?array
    {
        $owner = null;
        $columns = [];
        $directions = [];

        foreach ($items as $item) {
            $expression = $item->expr;

            if ($expression === null || $expression->column === null || $expression->function !== null || $expression->subquery !== null) {
                return null;
            }

            $reference = $this->resolve($expression->table, $expression->column, [$scope], record: false);

            if ($reference === null || ($owner !== null && $owner !== $reference)) {
                return null;
            }

            $owner = $reference;
            $column = $this->spelled($reference, $expression->column);
            $columns[] = $column;

            // A column an equality or IS NULL pins does not affect the order.
            $pinned = in_array(['column' => $column, 'joinedTo' => null], $reference->equalities, true) || in_array($column, $reference->nulls, true);

            if ($item instanceof OrderKeyword && ! $pinned) {
                $directions[$item->type->name] = true;
            }
        }

        return $owner === null || count($directions) > 1 ? null : [$owner, $columns];
    }

    /**
     * The table reference a column belongs to, searching scopes innermost
     * first. Null for derived tables and columns that cannot be placed.
     *
     * @param  list<array<string, ?TableColumns>>  $chain
     * @param  bool  $record  whether a column that cannot be placed counts as unresolved
     */
    private function resolve(?string $qualifier, string $column, array $chain, bool $record = true): ?TableColumns
    {
        foreach ($chain as $scope) {
            if ($qualifier !== null) {
                if (array_key_exists($qualifier, $scope)) {
                    return $scope[$qualifier];
                }

                continue;
            }

            $tables = array_values(array_filter($scope));
            $owners = array_values(array_filter($tables, fn (TableColumns $reference) => in_array(strtolower($column), $this->columnsOf($reference->table), true)));

            if (count($owners) === 1) {
                return $owners[0];
            }

            if (count($owners) > 1) {
                break;
            }

            // Without schema for the only table in scope, the column is its own.
            if (count($scope) === 1 && count($tables) === 1 && $this->columnsOf($tables[0]->table) === []) {
                return $tables[0];
            }
        }

        $this->unresolved = $this->unresolved || $record;

        return null;
    }

    /**
     * @return list<string> lowercased
     */
    private function columnsOf(string $table): array
    {
        return array_keys($this->schema($table));
    }

    /**
     * @return array<string, string> lowercased column name => as the schema spells it
     */
    private function schema(string $table): array
    {
        if (! isset($this->columns[$table])) {
            $names = ($this->columnsOf)($table);
            $this->columns[$table] = array_combine(array_map(strtolower(...), $names), $names);
        }

        return $this->columns[$table];
    }

    /**
     * A column as the schema of the reference spells it: MySQL column names
     * are case-insensitive, index comparisons are not.
     */
    private function spelled(TableColumns $reference, string $column): string
    {
        return $this->schema($reference->table)[strtolower($column)] ?? $column;
    }

    /**
     * A column reference (`col`, `t.col`, `db.t.col`) starting at `$i`.
     *
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @return array{qualifier: ?string, column: string, next: int}|null
     */
    private function columnAt(array $tokens, int $i): ?array
    {
        if (($tokens[$i]['kind'] ?? null) !== 'identifier') {
            return null;
        }

        $parts = [$tokens[$i]['value']];
        $next = $i + 1;

        while (count($parts) < 3 && ($tokens[$next]['kind'] ?? null) === 'dot' && ($tokens[$next + 1]['kind'] ?? null) === 'identifier') {
            $parts[] = $tokens[$next + 1]['value'];
            $next += 2;
        }

        $column = (string) array_pop($parts);

        return ['qualifier' => $parts === [] ? null : (string) end($parts), 'column' => $column, 'next' => $next];
    }

    /**
     * A column reference that ends the token list.
     *
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     * @return array{qualifier: ?string, column: string, start: int}|null
     */
    private function trailingColumn(array $tokens): ?array
    {
        for ($start = max(0, count($tokens) - 5); $start < count($tokens); $start++) {
            $column = $this->columnAt($tokens, $start);

            if ($column !== null && $column['next'] === count($tokens)
                && ($start === 0 || $tokens[$start - 1]['kind'] !== 'dot')) {
                return ['qualifier' => $column['qualifier'], 'column' => $column['column'], 'start' => $start];
            }
        }

        return null;
    }

    /**
     * A value that reads no column: placeholders, literals, functions of them, subqueries.
     *
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     */
    private function constant(array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($token['kind'] === 'identifier') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{kind: string, value: string, at: int}>  $tokens
     */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open; $i < count($tokens); $i++) {
            if ($tokens[$i]['kind'] === 'open') {
                $depth++;
            } elseif ($tokens[$i]['kind'] === 'close' && --$depth === 0) {
                return $i;
            }
        }

        throw new UnparseableSql('unbalanced parentheses');
    }

    /**
     * @param  array{kind: string, value: string, at: int}  $token
     */
    private function isAnd(array $token): bool
    {
        return ($token['kind'] === 'keyword' && $token['value'] === 'AND') || ($token['kind'] === 'operator' && $token['value'] === '&&');
    }

    /**
     * @param  array{kind: string, value: string, at: int}  $token
     */
    private function isOr(array $token): bool
    {
        return ($token['kind'] === 'keyword' && in_array($token['value'], ['OR', 'XOR'], true))
            || ($token['kind'] === 'operator' && $token['value'] === '||');
    }

    private function unwrap(string $sql): string
    {
        $sql = trim($sql);

        return str_starts_with($sql, '(') && str_ends_with($sql, ')') ? substr($sql, 1, -1) : $sql;
    }

    /**
     * Significant tokens, each with the index of its raw text (whitespace
     * included) so a subquery can be cut out as written.
     *
     * Kinds: identifier, constant, keyword, function, operator, open, close,
     * comma, dot. A non-reserved keyword (`status`, `name`) is an identifier
     * unless it names a function call, follows a value, or is the unit of an
     * `INTERVAL ? DAY`.
     *
     * @return array{list<array{kind: string, value: string, at: int}>, list<string>}
     */
    private function tokens(string $sql): array
    {
        $lexer = new Lexer($sql);
        $raw = [];
        $tokens = [];
        $interval = false;

        /** @var list<Token> $all */
        $all = array_values(array_filter($lexer->list->tokens, fn (Token $token) => $token->type !== TokenType::Delimiter));

        foreach ($all as $index => $token) {
            $raw[] = $token->token;

            if ($token->type === TokenType::Whitespace || $token->type === TokenType::Comment) {
                continue;
            }

            $next = null;

            for ($j = $index + 1; $j < count($all); $j++) {
                if ($all[$j]->type !== TokenType::Whitespace && $all[$j]->type !== TokenType::Comment) {
                    $next = $all[$j];

                    break;
                }
            }

            $previous = $tokens === [] ? null : $tokens[array_key_last($tokens)];
            $kind = $this->kind($token, $next, $previous);

            // The unit ends `INTERVAL <value> <unit>`, wherever the lexer places it.
            if ($interval && in_array($previous['kind'] ?? null, ['constant', 'identifier', 'close'], true)
                && in_array(strtoupper($token->token), self::INTERVAL_UNITS, true)) {
                $kind = 'keyword';
                $interval = false;
            }

            if (strtoupper($token->token) === 'INTERVAL' && $token->type === TokenType::Keyword) {
                $interval = true;
            }
            // A keyword read as a column keeps the spelling it was written in.
            $value = $kind === 'identifier' && $token->type === TokenType::Keyword ? $token->token : $this->value($token);
            $tokens[] = ['kind' => $kind, 'value' => $value, 'at' => $index];
        }

        return [$tokens, $raw];
    }

    /**
     * @param  array{kind: string, value: string, at: int}|null  $previous
     */
    private function kind(Token $token, ?Token $next, ?array $previous): string
    {
        $value = $token->token;

        return match ($token->type) {
            TokenType::Operator => match ($value) {
                '(' => 'open',
                ')' => 'close',
                ',' => 'comma',
                '.' => 'dot',
                default => 'operator',
            },
            TokenType::Symbol => ($token->flags & Token::FLAG_SYMBOL_BACKTICK) !== 0 ? 'identifier' : 'constant',
            TokenType::None => $next?->token === '(' ? 'function' : 'identifier',
            TokenType::Keyword => match (true) {
                $next?->token === '(' && ($token->flags & Token::FLAG_KEYWORD_FUNCTION) !== 0 => 'function',
                ($token->flags & Token::FLAG_KEYWORD_RESERVED) !== 0 => 'keyword',
                ($token->flags & Token::FLAG_KEYWORD_COMPOSED) !== 0 => 'keyword',
                in_array($previous['kind'] ?? null, ['constant', 'identifier', 'close'], true) => 'keyword',
                default => 'identifier',
            },
            TokenType::Bool, TokenType::Number, TokenType::String => 'constant',
            default => 'keyword',
        };
    }

    private function value(Token $token): string
    {
        return match ($token->type) {
            TokenType::Keyword => (string) ($token->keyword ?? strtoupper($token->token)),
            TokenType::Operator => strtoupper($token->token),
            TokenType::Symbol, TokenType::None => (string) $token->value,
            default => $token->token,
        };
    }
}
