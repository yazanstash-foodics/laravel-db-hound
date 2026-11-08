<?php

namespace Foodics\DbHound\Analysis;

/**
 * An existing index another index on the same table already covers: it
 * costs every write and serves no lookup the covering one does not.
 */
final readonly class RedundantIndex
{
    /**
     * @param  bool  $exact  whether the two have the same columns in the same order, else `$redundant`'s are a strict left prefix of `$covering`'s
     * @param  ?list<string>  $lostOrder  the order `$redundant` holds with the primary key InnoDB appends to it, when `$covering` does not hold it too: dropping `$redundant` loses it
     */
    public function __construct(
        public string $connection,
        public string $table,
        public Index $redundant,
        public Index $covering,
        public bool $exact,
        public ?array $lostOrder = null,
    ) {}

    /**
     * The redundant indexes among a table's, each with the index covering
     * it. Only a visible index other than the primary key can be redundant:
     * a FULLTEXT or SPATIAL one serves no lookup a B-tree does, and an
     * invisible one serves none. A non-unique one is covered by a visible
     * index of its type with the same columns in the same order (of two
     * non-unique ones, the first by name is kept), or by a visible B-tree
     * whose declared columns it is a strict left prefix of. A unique one
     * enforces a constraint, so only the primary key or another visible
     * unique index of its type with the same columns in the same order
     * covers it, keeping that constraint (of two unique ones, the first by
     * name is kept). On a table without a primary key no unique index is
     * redundant: InnoDB clusters the rows on its first NOT NULL unique index,
     * which the index list does not tell apart, and dropping that one needs
     * a full table copy. Of several covering ones, the one with the most columns comes
     * first (the one an engineer keeps when dropping every redundant one),
     * then the primary key, a unique one, and the first by name.
     *
     * Key parts are compared as declared: a column indexed on a prefix of
     * its values matches only the same prefix of it, the key parts compared
     * must run in the same directions or all reversed (`(a, b DESC)` serves
     * `ORDER BY a, b DESC`, which neither `(a, b)` nor `(a, b, c)` does),
     * and `(a)` is a left prefix of
     * `(a, b)`, though with the primary key InnoDB appends it holds the
     * order `(a, id)`, which `(a, b)` does not (`$lostOrder`).
     *
     * @param  list<Index>  $indexes
     * @return list<self>
     */
    public static function among(string $connection, string $table, array $indexes, PrimaryKey $primaryKey = new PrimaryKey): array
    {
        $redundant = [];
        $hasPrimary = array_any($indexes, fn (Index $index) => $index->isPrimary());

        foreach ($indexes as $index) {
            if ($index->isPrimary() || ! $index->visible || in_array(strtoupper($index->type), ['FULLTEXT', 'SPATIAL'], true) || ($index->unique && ! $hasPrimary)) {
                continue;
            }

            $covering = [];

            foreach ($indexes as $other) {
                if ($other === $index || ! $other->visible || strtoupper($other->type) !== strtoupper($index->type)) {
                    continue;
                }

                $directed = self::sameOrReversed($index->directions(), array_slice($other->directions(), 0, count($index->columns)));

                $exact = $directed
                    && $other->keyParts() === $index->keyParts()
                    && ($index->unique
                        ? $other->unique && ($other->isPrimary() || strcmp($other->name, $index->name) < 0)
                        : $other->unique || strcmp($other->name, $index->name) < 0);

                $prefix = $directed
                    && ! $index->unique
                    && strtoupper($index->type) === 'BTREE'
                    && count($index->columns) < count($other->columns)
                    && array_slice($other->keyParts(), 0, count($index->columns)) === $index->keyParts();

                if ($exact || $prefix) {
                    $held = $primaryKey->appendedTo($index->columns);
                    $kept = array_slice($primaryKey->appendedTo($other->columns), 0, count($held)) === $held
                        && self::sameOrReversed(self::heldDirections($index, $held), self::heldDirections($other, $held));

                    $covering[] = new self($connection, $table, $index, $other, $exact, $kept ? null : $held);
                }
            }

            usort($covering, fn (self $a, self $b) => [count($b->covering->columns), $b->covering->isPrimary(), $b->covering->unique, $a->covering->name]
                <=> [count($a->covering->columns), $a->covering->isPrimary(), $a->covering->unique, $b->covering->name]);

            if ($covering !== []) {
                $redundant[] = $covering[0];
            }
        }

        return $redundant;
    }

    /**
     * Whether an index stored in the `$b` directions serves every order one
     * in the `$a` directions does: the same directions, or all of them
     * reversed, which a backward scan reads.
     *
     * @param  list<bool>  $a
     * @param  list<bool>  $b
     */
    private static function sameOrReversed(array $a, array $b): bool
    {
        return $a === $b || $a === array_map(fn (bool $descending) => ! $descending, $b);
    }

    /**
     * The direction of each of the first `count($held)` key parts `$index`
     * holds, the primary key columns InnoDB appends to it (ascending) included.
     *
     * @param  list<string>  $held
     * @return list<bool>
     */
    private static function heldDirections(Index $index, array $held): array
    {
        return array_slice([...$index->directions(), ...array_fill(0, count($held), false)], 0, count($held));
    }
}
