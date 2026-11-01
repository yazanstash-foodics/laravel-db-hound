<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for query capture. When false (the default) db-hound records
    | nothing, so installing the package changes no production behavior.
    |
    */

    'enabled' => (bool) env('DB_HOUND_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Redis Store
    |--------------------------------------------------------------------------
    |
    | Captured aggregates are buffered in Redis until `db-hound:flush` moves them
    | into the `db_hound_queries` table. `connection` names a connection from
    | `database.redis`; `prefix` namespaces every key db-hound writes.
    |
    */

    'redis' => [
        'connection' => env('DB_HOUND_REDIS_CONNECTION', 'default'),
        'prefix' => env('DB_HOUND_REDIS_PREFIX', 'db-hound:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Report
    |--------------------------------------------------------------------------
    |
    | `db-hound:analyze` writes `report-{timestamp}.md` and `latest.md` into
    | `directory` on the given filesystem disk.
    |
    */

    'report' => [
        'disk' => env('DB_HOUND_REPORT_DISK', 'local'),
        'directory' => env('DB_HOUND_REPORT_DIRECTORY', 'db-hound'),
    ],

];
