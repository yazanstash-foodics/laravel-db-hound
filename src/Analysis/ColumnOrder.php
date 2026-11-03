<?php

namespace Foodics\DbHound\Analysis;

/**
 * The order of an index's columns, as far as the queries it serves fix it:
 * consecutive blocks of columns, each in its preferred order, whose order
 * inside the block is free (equality columns, which an index serves in any
 * order). Optional columns are weak filters: they barely narrow the rows
 * read, so an index that leaves them out still serves the queries, which
 * filter the rows it returns on them instead.
 */
final readonly class ColumnOrder
{
    /** @var list<non-empty-list<string>> */
    public array $blocks;

    /**
     * @var list<string>
     */
    public array $optional;

    /**
     * @param  list<list<string>>  $blocks  empty blocks are dropped
     * @param  list<string>  $optional  the columns the served queries can do without
     */
    public function __construct(array $blocks, array $optional = [])
    {
        $this->blocks = array_values(array_filter($blocks, fn (array $block) => $block !== []));
        $this->optional = array_values(array_intersect(array_unique($optional), $this->columns()));
    }

    /**
     * The columns in this order exactly.
     *
     * @param  list<string>  $columns
     */
    public static function fixed(array $columns): self
    {
        return new self(array_map(fn (string $column) => [$column], $columns));
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_merge([], ...$this->blocks);
    }

    /**
     * This order cut after its first `$count` columns.
     */
    public function firstColumns(int $count): self
    {
        $blocks = [];

        foreach ($this->blocks as $block) {
            $blocks[] = array_slice($block, 0, max(0, $count));
            $count -= count($block);
        }

        return new self($blocks, $this->optional);
    }

    /**
     * This order, narrowed so that `$prefix` (in one of the orders it
     * allows) starts it: `$prefix`'s optional columns that this order has
     * next come first, and, by `$leeway`, those it does not are left out
     * (Prefix), and this order's own optional columns too where they would
     * come between columns `$prefix` needs (Thin). Null when no order of
     * the two allows that.
     */
    public function startingWith(self $prefix, Leeway $leeway = Leeway::Prefix): ?self
    {
        $blocks = $this->blocks;
        $narrowed = [];
        $at = 0;
        $thin = $leeway === Leeway::Thin;

        foreach ($prefix->blocks as $wanted) {
            $optional = $leeway !== Leeway::None && array_diff($wanted, $prefix->optional) === [];

            while ($wanted !== []) {
                $current = $blocks[$at] ?? [];
                $shared = array_values(array_intersect($current, $wanted));

                if ($current !== [] && count($shared) === count($current)) {
                    // The whole block comes next, in its own order.
                    $narrowed[] = $current;
                    $wanted = array_values(array_diff($wanted, $current));
                    $at++;
                } elseif ($shared !== [] && ($optional || count($shared) === count($wanted))) {
                    // The block starts with the wanted columns it has.
                    $narrowed[] = $shared;
                    $blocks[$at] = array_values(array_diff($current, $shared));
                    $wanted = [];
                } elseif ($optional) {
                    // The rest of the weak filters are left out.
                    $wanted = [];
                } elseif ($thin && array_diff(array_intersect($current, $this->optional), $wanted) !== []) {
                    // This order's weak filters make way.
                    $blocks[$at] = array_values(array_diff($current, array_diff($this->optional, $wanted)));

                    if ($blocks[$at] === []) {
                        $at++;
                    }
                } else {
                    return null;
                }
            }
        }

        $required = array_diff($prefix->columns(), $prefix->optional);

        return new self([...$narrowed, ...array_slice($blocks, $at)], array_values(array_diff($this->optional, $required)));
    }
}
