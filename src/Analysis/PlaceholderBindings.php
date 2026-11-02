<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Contracts\Driver;

/**
 * Stand-in bindings for EXPLAINing a Fingerprint without its Sample (none was
 * stored, it expired, or it cannot be decrypted): one value per `?`, typed
 * after the column it is compared with, so the plan matches what real values
 * would get. A number compared with a string column, for one, would keep
 * MySQL from using that column's index.
 */
final class PlaceholderBindings
{
    private const NAME = '(?:`(?:[^`]|``)+`|[\w$]+)';

    /** Words that follow a table reference instead of an alias. */
    private const NOT_ALIASES = 'cross|except|for|force|full|group|having|ignore|inner|intersect|join|key|left|limit'
        .'|lock|natural|offset|on|order|outer|partition|right|straight_join|union|use|using|where|window';

    private const STRING = 'a';

    /** JSON functions whose arguments are all JSON documents. */
    private const JSON_DOCUMENTS = ['json_overlaps', 'json_merge', 'json_merge_patch', 'json_merge_preserve',
        'json_schema_valid', 'json_schema_validation_report'];

    /** Data types that take a length or precision in CAST and CONVERT. */
    private const SIZED_TYPES = ['binary', 'char', 'nchar', 'varchar', 'decimal', 'dec', 'numeric', 'fixed', 'float',
        'double', 'real', 'datetime', 'time', 'timestamp'];

    /**
     * Null when a `?` stands for a literal MySQL does not accept as a
     * parameter (a CAST type's length, for one), so only a Sample can be
     * explained.
     *
     * @return list<int|string>|null
     */
    public function for(string $sql, Driver $driver): ?array
    {
        $tables = $this->tables($sql);
        $types = [];

        $columnType = function (?string $qualifier, string $column) use ($tables, $driver, &$types): ?string {
            $candidates = $qualifier === null ? array_unique(array_values($tables)) : [$tables[$qualifier] ?? $qualifier];

            foreach ($candidates as $table) {
                $types[$table] ??= $driver->columnTypes($table);

                if (isset($types[$table][$column])) {
                    return $types[$table][$column];
                }
            }

            return null;
        };

        preg_match_all('/`(?:[^`]|``)*`|\?/', $sql, $matches, PREG_OFFSET_CAPTURE);

        $bindings = [];

        foreach ($matches[0] as [$token, $offset]) {
            if ($token !== '?') {
                continue;
            }

            $binding = $this->placeholder(substr($sql, 0, $offset), $columnType);

            if ($binding === null) {
                return null;
            }

            $bindings[] = $binding;
        }

        return $bindings;
    }

    /**
     * @param  callable(?string, string): ?string  $columnType
     */
    private function placeholder(string $before, callable $columnType): int|string|null
    {
        $call = $this->enclosingCall($before);

        if ($call !== null && $call['type']) {
            return null;
        }

        if ($call !== null && str_starts_with($call['name'], 'json_')) {
            return $this->jsonArgument($call['name'], $call['argument']);
        }

        if (preg_match('/\b(?:limit|offset)\s*$|\blimit\s+\?\s*,\s*$/i', $before)) {
            return 1;
        }

        $column = '(?:(?<qualifier>'.self::NAME.')\.)?(?<column>'.self::NAME.')';
        $comparison = '(?:<=>|!=|<>|<=|>=|=|<|>|(?:not\s+)?like|(?:not\s+)?in\s*\(|(?:not\s+)?between)';

        if (! preg_match('/'.$column.'\s*'.$comparison.'\s*$/i', $before, $m)
            && ! preg_match('/'.$column.'\s+(?:not\s+)?between\s+\?\s+and\s*$/i', $before, $m)) {
            return self::STRING;
        }

        $qualifier = $m['qualifier'] === '' ? null : $this->unquote($m['qualifier']);

        return $this->valueFor($columnType($qualifier, $this->unquote($m['column'])));
    }

    /**
     * A valid JSON document, path or search mode for a JSON function's
     * argument, by position.
     */
    private function jsonArgument(string $function, int $argument): string
    {
        return match (true) {
            $function === 'json_contains' => $argument <= 1 ? 'null' : '$',
            $argument === 0, in_array($function, self::JSON_DOCUMENTS, true) => 'null',
            in_array($function, ['json_search', 'json_contains_path'], true) && $argument === 1 => 'one',
            $function === 'json_search' && $argument === 2 => self::STRING,
            $function === 'json_search' && $argument === 3 => '',
            default => '$',
        };
    }

    /**
     * The function call the end of `$before` sits directly inside, if any:
     * its lowercased name, which argument comes next, and whether the
     * parentheses are a CAST / CONVERT type's (`decimal(`, `char(`).
     *
     * @return array{name: string, argument: int, type: bool}|null
     */
    private function enclosingCall(string $before): ?array
    {
        preg_match_all('/`(?:[^`]|``)*`|[\w$]+|[(),]|[^\s\w$(),`]+/', $before, $matches);

        /** @var list<array{name: string, argument: int, type: bool}|null> $calls */
        $calls = [];
        $previous = [null, null];

        foreach ($matches[0] as $token) {
            if ($token === '(') {
                [$beforeName, $name] = $previous;
                $name = $name !== null && preg_match('/^[a-z_]\w*$/i', $name) ? strtolower($name) : null;
                $outer = end($calls) ?: null;

                $calls[] = $name === null ? null : [
                    'name' => $name,
                    'argument' => 0,
                    'type' => in_array($name, self::SIZED_TYPES, true)
                        && (strtolower((string) $beforeName) === 'as' || ($beforeName === ',' && $outer !== null && $outer['name'] === 'convert')),
                ];
            } elseif ($token === ')') {
                array_pop($calls);
            } elseif ($token === ',' && $calls !== [] && end($calls) !== null) {
                $calls[array_key_last($calls)]['argument']++;
            }

            $previous = [$previous[1], $token];
        }

        return $calls === [] ? null : end($calls);
    }

    private function valueFor(?string $type): int|string
    {
        $base = strtolower((string) preg_replace('/[\s(].*$/s', '', (string) $type));

        return match ($base) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit' => 1,
            'year' => 2000,
            'date' => '2000-01-01',
            'datetime', 'timestamp' => '2000-01-01 00:00:00',
            'time' => '00:00:00',
            default => self::STRING,
        };
    }

    /**
     * Tables the query reads, keyed by the names it refers to them by
     * (alias and table name).
     *
     * @return array<string, string>
     */
    private function tables(string $sql): array
    {
        $reference = fn (string $table, string $alias) => '('.$table.self::NAME.'(?:\.'.self::NAME.')?)'
            .'(?:\s+(?:as\s+)?(?!(?:'.self::NOT_ALIASES.')\b)('.$alias.self::NAME.'))?';
        $unnamed = $reference('?:', '?:');

        preg_match_all('/\b(?:from|join)\s+('.$unnamed.'(?:\s*,\s*'.$unnamed.')*)/i', $sql, $lists);

        $tables = [];

        foreach ($lists[1] as $list) {
            preg_match_all('/(?:^|,)\s*'.$reference('?<table>', '?<alias>').'/i', $list, $references, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

            foreach ($references as $ref) {
                $parts = preg_split('/\.(?=(?:[^`]*`[^`]*`)*[^`]*$)/', (string) $ref['table']) ?: [];
                $table = $this->unquote((string) end($parts));

                $tables[$table] = $table;

                if ($ref['alias'] !== null) {
                    $tables[$this->unquote($ref['alias'])] = $table;
                }
            }
        }

        return $tables;
    }

    private function unquote(string $name): string
    {
        return str_starts_with($name, '`') ? str_replace('``', '`', substr($name, 1, -1)) : $name;
    }
}
