<?php

namespace Foodics\DbHound\Analysis;

/**
 * The SELECT db-hound EXPLAINs for a captured statement, with the bindings it
 * needs. A SELECT is explained as is. An UPDATE or DELETE becomes
 * `SELECT * FROM <its tables> <its WHERE / ORDER BY / LIMIT>`, which reads the
 * rows the write would touch, so the plan shows how its predicates are found;
 * an UPDATE's SET bindings are dropped with its SET clause.
 */
final readonly class ExplainableQuery
{
    /**
     * Placeholders, separators, nesting, words and quoted identifiers, skipping quoted
     * strings and comments (which may contain `?` or keywords).
     */
    private const TOKENS = <<<'REGEX'
        ~
          (?<identifier> `(?:[^`]|``)*+` )
        | (?<skip>
              '(?:[^'\\]|\\.|'')*+'
            | "(?:[^"\\]|\\.|"")*+"
            | /\*.*?\*/
            | (?:--(?=\s|$)|\#)[^\n]*+
          )
        | (?<open> \( )
        | (?<close> \) )
        | (?<placeholder> \? )
        | (?<separator> ; )
        | (?<word> [a-zA-Z_][\w$]*+ )
        ~xs
        REGEX;

    /** Words that end an UPDATE's SET clause. */
    private const AFTER_SET = ['where', 'order', 'limit'];

    /** Modifiers that may follow UPDATE / DELETE before the table references. */
    private const MODIFIERS = ['low_priority', 'quick', 'ignore'];

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    private function __construct(
        public string $sql,
        public array $bindings,
    ) {}

    /**
     * Whether the statement is a single SELECT: its first keyword outside
     * comments and parentheses is SELECT, and no statement follows it.
     */
    public static function isSelect(string $sql): bool
    {
        $tokens = self::tokens($sql);
        $separator = array_search('separator', array_column($tokens, 'type'), true);

        return self::firstKeyword($tokens) === 'select'
            && ($separator === false || $separator === count($tokens) - 1);
    }

    /**
     * Null when the statement is not a SELECT, UPDATE or DELETE, or its
     * bindings do not match its placeholders.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public static function from(string $sql, array $bindings = []): ?self
    {
        $tokens = self::tokens($sql);
        $words = array_values(array_filter($tokens, fn (array $token) => $token['type'] === 'word' && $token['depth'] === 0));

        return match (self::firstKeyword($tokens)) {
            'select' => new self($sql, $bindings),
            'update' => self::fromUpdate($sql, $bindings, $tokens, $words),
            'delete' => self::fromDelete($sql, $bindings, $tokens, $words),
            default => null,
        };
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @param  list<array{type: string, text: string, offset: int, depth: int}>  $tokens
     * @param  list<array{type: string, text: string, offset: int, depth: int}>  $words
     */
    private static function fromUpdate(string $sql, array $bindings, array $tokens, array $words): ?self
    {
        $set = self::firstWord($words, ['set']);

        if ($set === null) {
            return null;
        }

        $end = self::firstWord(array_filter($words, fn (array $word) => $word['offset'] > $set['offset']), self::AFTER_SET);
        $setEnd = $end === null ? strlen($sql) : $end['offset'];

        $tables = self::withoutModifiers(substr($sql, $words[0]['offset'] + strlen('update'), $set['offset'] - $words[0]['offset'] - strlen('update')));
        $rest = substr($sql, $setEnd);

        $placeholders = array_values(array_filter($tokens, fn (array $token) => $token['type'] === 'placeholder'));

        if ($bindings !== [] && (! array_is_list($bindings) || count($bindings) !== count($placeholders))) {
            return null;
        }

        $kept = [];

        foreach ($placeholders as $position => $placeholder) {
            if ($placeholder['offset'] < $set['offset'] || $placeholder['offset'] >= $setEnd) {
                $kept[] = $bindings[$position] ?? null;
            }
        }

        return new self(self::select($tables, $rest), $bindings === [] ? [] : $kept);
    }

    /**
     * `DELETE FROM t ...`, `DELETE t1 FROM t1 JOIN ...` and
     * `DELETE FROM t1 USING t1 JOIN ...` all read the rows after FROM (or
     * USING); every placeholder stays.
     *
     * @param  array<int|string, mixed>  $bindings
     * @param  list<array{type: string, text: string, offset: int, depth: int}>  $tokens
     * @param  list<array{type: string, text: string, offset: int, depth: int}>  $words
     */
    private static function fromDelete(string $sql, array $bindings, array $tokens, array $words): ?self
    {
        $from = self::firstWord($words, ['from']);

        if ($from === null) {
            return null;
        }

        // Any name between DELETE and FROM, quoted or not, is a target table.
        $targets = array_filter($tokens, fn (array $token) => $token['type'] !== 'placeholder' && $token['depth'] === 0
            && $token['offset'] > $words[0]['offset'] && $token['offset'] < $from['offset']);
        $deleteFrom = array_filter($targets, fn (array $token) => ! in_array($token['text'], self::MODIFIERS, true)) === [];

        $start = $from;

        if ($deleteFrom) {
            $start = self::firstWord(array_filter($words, fn (array $word) => $word['offset'] > $from['offset']), ['using']) ?? $from;
        }

        $tables = substr($sql, $start['offset'] + strlen($start['text']));

        return new self(self::select($tables, ''), $bindings);
    }

    /**
     * @param  list<array{type: string, text: string, offset: int, depth: int}>  $tokens
     */
    private static function firstKeyword(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if ($token['type'] === 'word' && $token['depth'] === 0) {
                return $token['text'];
            }
        }

        return null;
    }

    /**
     * Whitespace is kept as written: a line comment ends at its newline.
     */
    private static function select(string $tables, string $rest): string
    {
        $select = 'select * from '.ltrim($tables);

        if ($rest !== '' && ! preg_match('/\s$/', $select)) {
            $select .= ' ';
        }

        return $select.$rest;
    }

    private static function withoutModifiers(string $tables): string
    {
        return (string) preg_replace('/^\s*(?:(?:low_priority|ignore)\s+)*/i', '', $tables);
    }

    /**
     * @param  array<array{type: string, text: string, offset: int, depth: int}>  $words
     * @param  list<string>  $texts
     * @return array{type: string, text: string, offset: int, depth: int}|null
     */
    private static function firstWord(array $words, array $texts): ?array
    {
        foreach ($words as $word) {
            if (in_array($word['text'], $texts, true)) {
                return $word;
            }
        }

        return null;
    }

    /**
     * Placeholders, statement separators, words and quoted identifiers with
     * their offset and parenthesis depth; words are lowercased.
     *
     * @return list<array{type: string, text: string, offset: int, depth: int}>
     */
    private static function tokens(string $sql): array
    {
        preg_match_all(self::TOKENS, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $tokens = [];
        $depth = 0;

        foreach ($matches as $match) {
            [$text, $offset] = $match[0];

            if ($match['open'][0] !== null) {
                $depth++;
            } elseif ($match['close'][0] !== null) {
                $depth = max(0, $depth - 1);
            } elseif ($match['separator'][0] !== null) {
                $tokens[] = ['type' => 'separator', 'text' => ';', 'offset' => $offset, 'depth' => $depth];
            } elseif ($match['placeholder'][0] !== null) {
                $tokens[] = ['type' => 'placeholder', 'text' => '?', 'offset' => $offset, 'depth' => $depth];
            } elseif ($match['word'][0] !== null) {
                $tokens[] = ['type' => 'word', 'text' => strtolower($text), 'offset' => $offset, 'depth' => $depth];
            } elseif ($match['identifier'][0] !== null) {
                $tokens[] = ['type' => 'identifier', 'text' => $text, 'offset' => $offset, 'depth' => $depth];
            }
        }

        return $tokens;
    }
}
