<?php

namespace Foodics\DbHound\Analysis;

use RuntimeException;
use Throwable;

/**
 * Carries a failed schema lookup out of the SQL parser, so it is reported as
 * the database's failure rather than the parser's.
 *
 * @internal
 */
final class TableLookupFailed extends RuntimeException
{
    public function __construct(public readonly Throwable $failure)
    {
        parent::__construct($failure->getMessage(), 0, $failure);
    }
}
