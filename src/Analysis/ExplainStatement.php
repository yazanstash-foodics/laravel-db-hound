<?php

namespace Foodics\DbHound\Analysis;

use Foodics\DbHound\Capture\Sample;
use Foodics\DbHound\Contracts\Driver;

/**
 * The SELECT and bindings db-hound EXPLAINs a Fingerprint with: its Sample
 * (a write rewritten as the SELECT of the rows it touches), or, without a
 * usable Sample, the Fingerprint itself with placeholder bindings typed
 * after the columns they are compared with.
 */
final readonly class ExplainStatement
{
    /**
     * @param  array<int|string, mixed>  $bindings
     */
    private function __construct(
        public string $sql,
        public array $bindings,
    ) {}

    /**
     * Null when only a Sample could be explained: the Fingerprint has a
     * literal MySQL does not accept as a parameter. Looking up column types
     * for the placeholders may throw.
     *
     * @param  ExplainableQuery  $shape  the Fingerprint, as the SELECT to explain
     */
    public static function for(
        ExplainableQuery $shape,
        ?Sample $sample,
        Driver $driver,
        PlaceholderBindings $placeholders = new PlaceholderBindings,
    ): ?self {
        $explainable = $sample === null ? null : ExplainableQuery::from($sample->sql, $sample->bindings);

        if ($explainable !== null) {
            return new self($explainable->sql, $explainable->bindings);
        }

        $bindings = $placeholders->for($shape->sql, $driver);

        return $bindings === null ? null : new self($shape->sql, $bindings);
    }
}
