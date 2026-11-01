<?php

namespace Foodics\DbHound\Drivers;

use Foodics\DbHound\Contracts\Driver;
use Illuminate\Database\DatabaseManager;

/**
 * The Driver a captured connection's Fingerprints are explained and its
 * schema inspected with: on its `db-hound.analysis.connections` mapping (a
 * read replica, say), or on itself, with EXPLAIN under the configured
 * `max_execution_time`.
 */
final class AnalysisDrivers
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    /**
     * @param  array<array-key, mixed>  $connections  captured connection => analysis connection
     */
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly array $connections = [],
        private readonly int $maxExecutionTimeMs = 5000,
    ) {}

    /**
     * The analysis connection's name for a captured connection.
     */
    public function connectionFor(string $connection): string
    {
        $mapped = $this->connections[$connection] ?? null;

        return is_string($mapped) && $mapped !== '' ? $mapped : $connection;
    }

    public function driverFor(string $connection): Driver
    {
        return $this->drivers[$connection] ??= new MySqlDriver(
            $this->databases->connection($this->connectionFor($connection)),
            $this->maxExecutionTimeMs,
        );
    }
}
