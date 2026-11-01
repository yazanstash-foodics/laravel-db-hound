<?php

namespace Foodics\DbHound\Analysis;

/**
 * Engine-neutral summary of an EXPLAIN. Holds no literal values.
 */
final readonly class Plan
{
    /**
     * @param  list<TableAccess>  $accesses
     */
    public function __construct(
        public array $accesses,
        public bool $usingFilesort = false,
        public bool $usingTemporary = false,
    ) {}

    /**
     * @return list<string>
     */
    public function extras(): array
    {
        return array_values(array_filter([
            $this->usingFilesort ? 'Using filesort' : null,
            $this->usingTemporary ? 'Using temporary' : null,
        ]));
    }
}
