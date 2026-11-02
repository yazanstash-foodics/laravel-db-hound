<?php

namespace Foodics\DbHound\Analysis;

/**
 * The order of an index's columns, as far as the queries it serves fix it:
 * consecutive blocks of columns, each in its preferred order, whose order
 * inside the block is free (equality columns, which an index serves in any
 * order).
 */
final readonly class ColumnOrder
{
    /** @var list<non-empty-list<string>> */
    public array $blocks;

    /**
     * @param  list<list<string>>  $blocks  empty blocks are dropped
     */
    public function __construct(array $blocks)
    {
        $this->blocks = array_values(array_filter($blocks, fn (array $block) => $block !== []));
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
     * This order, narrowed so that `$prefix` (in one of the orders it
     * allows) starts it. Null when no order of the two allows that.
     */
    public function startingWith(self $prefix): ?self
    {
        $blocks = $this->blocks;
        $narrowed = [];
        $at = 0;

        foreach ($prefix->blocks as $wanted) {
            while ($wanted !== []) {
                if (! isset($blocks[$at])) {
                    return null;
                }

                $current = $blocks[$at];
                $shared = array_values(array_intersect($current, $wanted));

                if (count($shared) === count($current)) {
                    // The whole block comes next, in its own order.
                    $narrowed[] = $current;
                    $wanted = array_values(array_diff($wanted, $current));
                    $at++;
                } elseif ($shared !== [] && count($shared) === count($wanted)) {
                    // The block starts with the wanted columns.
                    $narrowed[] = $shared;
                    $blocks[$at] = array_values(array_diff($current, $shared));
                    $wanted = [];
                } else {
                    return null;
                }
            }
        }

        return new self([...$narrowed, ...array_slice($blocks, $at)]);
    }
}
