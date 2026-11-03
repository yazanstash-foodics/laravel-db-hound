<?php

namespace Foodics\DbHound\Analysis;

/**
 * Which weak filters ColumnOrder::startingWith() may leave out to make one
 * order start another. The cases go from the least left out to the most.
 */
enum Leeway
{
    /** None: both orders keep every column, as they are. */
    case None;

    /** The prefix's weak filters that the order does not have next. */
    case Prefix;

    /** Those, and the order's own weak filters where they would come between columns the prefix needs. */
    case Thin;
}
