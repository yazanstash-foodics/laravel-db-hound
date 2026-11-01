<?php

namespace Foodics\DbHound\Capture;

/**
 * Hot-path SQL normalizer (a regex tokenizer, no AST): literals become `?`,
 * comments are dropped, whitespace and keyword case are normalized, and `IN`
 * and multi-row `VALUES` lists of any length collapse to one entry, so queries
 * that differ only in values share one Fingerprint. Backtick-quoted
 * identifiers are never altered. SQL it cannot normalize safely gets no
 * Fingerprint at all.
 */
final class Fingerprinter
{
    /**
     * Pass 1: literals become `?` and comments become a space. A quote or
     * backslash left over (an unterminated literal, or one ending in a
     * backslash under NO_BACKSLASH_ESCAPES) means the literals could not be
     * paired reliably.
     */
    private const LITERALS = <<<'REGEX'
        ~
          (?<identifier> `(?:[^`]++|``)*+` )
        | (?<literal>
              \b(?:[xX]'[0-9a-fA-F]*+'|[bB]'[01]*+')
            | '(?:[^'\\]++|\\.|'')*+'
            | "(?:[^"\\]++|\\.|"")*+"
            | (?:(?<=[=<>(,])\s*+-\s*+)?
              (?<![\w$.])(?:0[xX][0-9a-fA-F]++|0[bB][01]++|(?:\d++\.?\d*+|\.\d++)(?:[eE][+-]?\d++)?)(?![\w$])
          )
        | (?<comment> /\*[^*]*+\*++(?:[^/*][^*]*+\*++)*+/ | (?:--(?=\s|$)|\#)[^\n]*+ )
        | (?<word> [a-zA-Z_$][\w$]* )
        | (?<stray> ['"`\\] )
        ~xs
        REGEX;

    /**
     * Pass 2: whitespace collapses to single spaces, with none inside
     * parentheses, exactly one after each comma and one on each side of a
     * comparison operator (but not of `->>` or shifts).
     */
    private const WHITESPACE = <<<'REGEX'
        ~
          (?<identifier> `(?:[^`]++|``)*+` )
        | \s*+(?<![-<>!=:])(?<operator> <=> | <> | != | <= | >= | := | = | < | > )(?![<>=])\s*+
        | (?<open> \(\s* )
        | (?<close> \s*\) )
        | (?<comma> \s*,\s* )
        | (?<space> \s+ )
        ~x
        REGEX;

    /**
     * Pass 3: value lists of any length collapse to one entry: `IN` lists of
     * placeholders or of same-width tuples, and multi-row `VALUES`.
     */
    private const VALUE_LISTS = [
        '/\bin ?\(\?(?:, \?)*+\)/' => 'in (?)',
        '/\bin ?\((\(\?(?:, \?)*+\))(?:, \1)*+\)/' => 'in ($1)',
        '/\b(values?) ?(\(\?(?:, \?)*+\))(?:, \2)++/' => '$1 $2',
    ];

    /**
     * Words lowercased wherever they appear unquoted: MySQL's reserved words,
     * common non-reserved keywords (interval units, clauses) and built-in
     * functions. Other identifiers are kept as written: table names can be
     * case-sensitive in MySQL.
     */
    private const KEYWORDS = [
        'abs', 'accessible', 'add', 'after', 'all', 'alter', 'analyze', 'and', 'any', 'as', 'asc',
        'asensitive', 'at', 'avg', 'before', 'begin', 'between', 'bigint', 'binary', 'bit_and', 'bit_or',
        'blob', 'both', 'btree', 'by', 'call', 'cascade', 'case', 'cast', 'ceil', 'ceiling', 'change',
        'char', 'char_length', 'character', 'charset', 'check', 'coalesce', 'collate', 'column', 'commit',
        'committed', 'concat', 'concat_ws', 'condition', 'constraint', 'continue', 'convert', 'count',
        'create', 'cross', 'cube', 'cume_dist', 'curdate', 'current', 'current_date', 'current_time',
        'current_timestamp', 'current_user', 'cursor', 'curtime', 'database', 'databases', 'date',
        'date_add', 'date_format', 'date_sub', 'datediff', 'datetime', 'day', 'day_hour', 'day_microsecond',
        'day_minute', 'day_second', 'dayofmonth', 'dayofweek', 'dayofyear', 'dec', 'decimal', 'declare',
        'default', 'delayed', 'delete', 'dense_rank', 'desc', 'describe', 'deterministic', 'distinct',
        'distinctrow', 'div', 'double', 'drop', 'dual', 'duplicate', 'each', 'else', 'elseif', 'empty',
        'enclosed', 'end', 'escape', 'escaped', 'except', 'exists', 'exit', 'explain', 'extended',
        'extract', 'false', 'fetch', 'field', 'find_in_set', 'first', 'first_value', 'float', 'floor',
        'following', 'for', 'force', 'foreign', 'from', 'from_unixtime', 'full', 'fulltext', 'function',
        'generated', 'get', 'global', 'grant', 'greatest', 'group', 'group_concat', 'grouping', 'groups',
        'hash', 'having', 'hex', 'high_priority', 'hour', 'hour_microsecond', 'hour_minute', 'hour_second',
        'if', 'ifnull', 'ignore', 'in', 'index', 'infile', 'inner', 'inout', 'insensitive', 'insert',
        'instr', 'int', 'integer', 'intersect', 'interval', 'into', 'is', 'isnull', 'isolation', 'iterate',
        'join', 'json', 'json_array', 'json_arrayagg', 'json_contains', 'json_extract', 'json_length',
        'json_object', 'json_objectagg', 'json_search', 'json_set', 'json_table', 'json_unquote',
        'json_valid', 'key', 'keys', 'kill', 'lag', 'last', 'last_insert_id', 'last_value', 'lateral',
        'lcase', 'lead', 'leading', 'least', 'leave', 'left', 'length', 'level', 'like', 'limit', 'linear',
        'lines', 'load', 'local', 'localtime', 'localtimestamp', 'locate', 'lock', 'locked', 'long', 'loop',
        'low_priority', 'lower', 'lpad', 'ltrim', 'match', 'max', 'maxvalue', 'md5', 'microsecond', 'mid',
        'min', 'minute', 'minute_microsecond', 'minute_second', 'mod', 'mode', 'modifies', 'month', 'names',
        'natural', 'next', 'not', 'now', 'nowait', 'nth_value', 'ntile', 'null', 'nullif', 'nulls',
        'numeric', 'of', 'offset', 'on', 'only', 'optimize', 'option', 'optionally', 'or', 'order', 'out',
        'outer', 'outfile', 'over', 'partition', 'percent_rank', 'position', 'pow', 'power', 'preceding',
        'precision', 'primary', 'procedure', 'purge', 'quarter', 'rand', 'range', 'rank', 'read', 'reads',
        'real', 'recursive', 'references', 'regexp', 'release', 'rename', 'repeat', 'repeatable', 'replace',
        'require', 'resignal', 'restrict', 'return', 'reverse', 'revoke', 'right', 'rlike', 'rollback',
        'rollup', 'round', 'row', 'row_number', 'rows', 'rpad', 'rtrim', 'schema', 'schemas', 'second',
        'second_microsecond', 'select', 'sensitive', 'separator', 'serializable', 'session', 'set', 'sha1',
        'sha2', 'share', 'show', 'sign', 'signal', 'signed', 'skip', 'smallint', 'some', 'spatial',
        'specific', 'sql', 'sql_big_result', 'sql_calc_found_rows', 'sql_small_result', 'sqlexception',
        'sqlstate', 'sqlwarning', 'sqrt', 'ssl', 'start', 'starting', 'std', 'stddev', 'stored',
        'str_to_date', 'straight_join', 'strcmp', 'subdate', 'substr', 'substring', 'substring_index',
        'sum', 'sysdate', 'system', 'table', 'temporary', 'terminated', 'then', 'time', 'timediff',
        'timestamp', 'timestampdiff', 'tinyint', 'to', 'to_days', 'trailing', 'transaction', 'trigger',
        'trim', 'true', 'truncate', 'ucase', 'unbounded', 'uncommitted', 'undo', 'unhex', 'union', 'unique',
        'unix_timestamp', 'unknown', 'unlock', 'unsigned', 'update', 'upper', 'usage', 'use', 'using',
        'utc_date', 'utc_time', 'utc_timestamp', 'uuid', 'value', 'values', 'varbinary', 'varchar',
        'varying', 'virtual', 'week', 'weekday', 'when', 'where', 'while', 'window', 'with', 'work',
        'write', 'xor', 'year', 'year_month', 'yearweek', 'zerofill',
    ];

    /**
     * Null when the SQL cannot be normalized safely (unpaired quotes, or the
     * regex engine gave up): such a query must not be captured, since an empty or partial
     * shape could leak literals or slip past table exclusions.
     */
    public function fingerprint(string $sql): ?Fingerprint
    {
        $keywords = self::keywords();
        $stray = false;

        $normalized = preg_replace_callback(self::LITERALS, static function (array $m) use ($keywords, &$stray): string {
            if (($m['stray'] ?? '') !== '') {
                $stray = true;
            }

            return match (true) {
                ($m['literal'] ?? '') !== '' => '?',
                ($m['comment'] ?? '') !== '' => ' ',
                ($m['word'] ?? '') !== '' => isset($keywords[$lower = strtolower($m[0])]) ? $lower : $m[0],
                default => $m[0],
            };
        }, $sql);

        if ($normalized === null || $stray) {
            return null;
        }

        $normalized = preg_replace_callback(self::WHITESPACE, static fn (array $m): string => match (true) {
            ($m['operator'] ?? '') !== '' => " {$m['operator']} ",
            ($m['open'] ?? '') !== '' => '(',
            ($m['close'] ?? '') !== '' => ')',
            ($m['comma'] ?? '') !== '' => ', ',
            ($m['space'] ?? '') !== '' => ' ',
            default => $m[0],
        }, $normalized);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace(array_keys(self::VALUE_LISTS), self::VALUE_LISTS, trim($normalized));

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return new Fingerprint($normalized, hash('xxh128', $normalized));
    }

    /**
     * @return array<string, true>
     */
    private static function keywords(): array
    {
        static $keywords = null;

        return $keywords ??= array_fill_keys(self::KEYWORDS, true);
    }
}
