<?php

namespace Foodics\DbHound\Capture;

use Foodics\DbHound\Persistence\QueryRepository;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;

/**
 * Which queries the Recorder keeps, from the `db-hound` config.
 *
 * Table lists hold names as the application knows them (without the
 * connection's table prefix) and accept `*` wildcards. A query is kept when it
 * touches at least one allowed table and no denied one. db-hound's own table
 * is always denied.
 */
final readonly class CapturePolicy
{
    /**
     * Table references after FROM / JOIN / UPDATE / INSERT / INTO and in DDL /
     * TRUNCATE statements, plus further references of a comma-separated list.
     * Names may be backticked or schema-qualified. Statement modifiers, index
     * hints, PARTITION clauses and an alias (any name but a keyword that can
     * follow a table reference) are skipped; derived tables are not names.
     */
    private const TABLES = <<<'REGEX'
        ~\b(?:
            (?:create|alter|drop|truncate|rename)\s+(?:temporary\s+)?table\s+(?:if\s+(?:not\s+)?exists\s+)?
          | (?:create|drop)\s+(?:(?:unique|fulltext|spatial)\s+)?index\s+(?&name)\s+on\s+
          | (?:from|join|straight_join)\s+(?:\(\s*)*
          | (?<!key\s)(?<!for\s)update\s+(?:(?:low_priority|ignore)\s+)*
          | insert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:into\s+)?
          | (?:into|truncate)\s+
          )
          (?<list> (?&ref) (?:\s*,\s*(?:\(\s*)*(?&ref))* )
          (?(DEFINE)
            (?<name>(?:`(?:[^`]|``)+`|[\w$]+)(?:\.(?:`(?:[^`]|``)+`|[\w$]+))?)
            (?<ref>(?!(?:select|with|table|values)\b)(?&name)(?&partition)?(?&alias)?(?&hint)*)
            (?<partition>\s+partition\s*\([^)]*\))
            (?<alias>\s+(?:as\s+)?(?!(?&keyword)\b)(?&name))
            (?<hint>\s+(?:use|force|ignore)\s+(?:index|key)(?:\s+for\s+(?:join|order\s+by|group\s+by))?\s*\([^)]*\))
            (?<keyword>cross|except|for|force|full|group|having|ignore|inner|intersect|into|join|key
              |left|limit|lock|natural|offset|on|order|outer|partition|procedure|right|set|straight_join
              |union|use|using|values?|where|window)
          )
        ~xi
        REGEX;

    private const NAME = '~(?:`(?:[^`]|``)+`|[\w$]+)(?:\.(?:`(?:[^`]|``)+`|[\w$]+))?~';

    /**
     * @param  list<string>  $allowedConnections  empty allows every connection
     * @param  list<string>  $deniedConnections  wins over the allow list
     * @param  list<string>  $allowedTables  empty allows every table
     * @param  list<string>  $deniedTables  wins over the allow list
     */
    public function __construct(
        private float $sampleRate = 1.0,
        private float $minDurationMs = 0.0,
        private array $allowedConnections = [],
        private array $deniedConnections = [],
        private array $allowedTables = [],
        private array $deniedTables = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `db-hound` config array
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            sampleRate: (float) ($config['sample_rate'] ?? 1.0),
            minDurationMs: (float) ($config['min_duration_ms'] ?? 0.0),
            allowedConnections: self::names($config['connections']['allow'] ?? []),
            deniedConnections: self::names($config['connections']['deny'] ?? []),
            allowedTables: self::names($config['tables']['allow'] ?? []),
            deniedTables: self::names($config['tables']['deny'] ?? []),
        );
    }

    /**
     * Roll the sampling dice for one unit of work (request, job, command).
     */
    public function samplesUnit(): bool
    {
        return match (true) {
            $this->sampleRate <= 0 => false,
            $this->sampleRate >= 1 => true,
            default => (bool) Lottery::odds($this->sampleRate)->choose(),
        };
    }

    /**
     * Whether a query that took `$ms` on `$connection` is worth capturing.
     */
    public function admits(string $connection, float $ms): bool
    {
        return $ms >= $this->minDurationMs
            && ! in_array($connection, $this->deniedConnections, true)
            && ($this->allowedConnections === [] || in_array($connection, $this->allowedConnections, true));
    }

    /**
     * Whether a query of this (normalized) SQL shape is worth capturing,
     * judged by the tables it touches. `$tablePrefix` is the connection's.
     */
    public function admitsTables(string $sql, string $tablePrefix = ''): bool
    {
        $tables = $this->tables($sql, $tablePrefix);

        // Fail closed: unreadable SQL might touch a denied table.
        if ($tables === null) {
            return false;
        }

        $denied = [QueryRepository::TABLE, ...$this->deniedTables];

        foreach ($tables as $table) {
            if (Str::is($denied, $table)) {
                return false;
            }
        }

        if ($this->allowedTables === []) {
            return true;
        }

        foreach ($tables as $table) {
            if (Str::is($this->allowedTables, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null unquoted, unqualified table names without
     *                           `$tablePrefix`; null when the regex engine fails
     */
    private function tables(string $sql, string $tablePrefix): ?array
    {
        $found = preg_match_all(self::TABLES, $sql, $lists);

        if ($found === false) {
            return null;
        }

        $tables = [];

        foreach ($lists['list'] as $list) {
            // Hint and partition lists hold commas too; drop them first.
            $references = preg_replace('/\([^()]*+\)/', '', $list);

            if ($references === null) {
                return null;
            }

            foreach (preg_split('/\s*,\s*/', $references) ?: [] as $entry) {
                if (! preg_match(self::NAME, $entry, $name)) {
                    continue;
                }

                $parts = explode('.', $name[0]);
                $table = str_replace('``', '`', trim(end($parts), '`'));

                if ($tablePrefix !== '' && str_starts_with($table, $tablePrefix)) {
                    $table = substr($table, strlen($tablePrefix));
                }

                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $names): array
    {
        return array_values(array_map(strval(...), array_filter((array) $names, is_scalar(...))));
    }
}
