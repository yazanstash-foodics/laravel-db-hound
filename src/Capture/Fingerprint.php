<?php

namespace Foodics\DbHound\Capture;

/**
 * The normalized shape of a query plus its hash: the aggregation key.
 */
final readonly class Fingerprint
{
    public function __construct(
        public string $sql,
        public string $hash,
    ) {}
}
