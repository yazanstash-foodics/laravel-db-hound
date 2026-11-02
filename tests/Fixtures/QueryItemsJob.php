<?php

namespace Foodics\DbHound\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * A queued job that runs `$queries` lookups against `$table`.
 */
class QueryItemsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $table,
        public int $queries,
    ) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->queries; $i++) {
            DB::table($this->table)->where('sku', "SKU-{$i}")->first();
        }
    }
}
