<?php

namespace Foodics\DbHound\Analysis;

use RuntimeException;

/**
 * The Column extractor could not make sense of a query.
 */
final class UnparseableSql extends RuntimeException {}
