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
    | Sampling
    |--------------------------------------------------------------------------
    |
    | The share of units of work (HTTP requests, queued jobs, Artisan commands,
    | Octane requests/tasks) that are captured, from 0.0 (none) to 1.0 (all).
    | The decision is made once per unit, so a sampled unit captures all of its
    | queries and frequency counts stay proportional.
    |
    */

    'sample_rate' => (float) env('DB_HOUND_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Minimum Duration
    |--------------------------------------------------------------------------
    |
    | Queries that take less than this many milliseconds are not captured.
    |
    */

    'min_duration_ms' => (float) env('DB_HOUND_MIN_DURATION_MS', 0),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Database connection names to capture. An empty `allow` list captures every
    | connection; `deny` always wins over `allow`.
    |
    */

    'connections' => [
        'allow' => [],
        'deny' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | Table names (without the connection's table prefix; `*` wildcards allowed)
    | to capture. A query is captured when it touches at least one allowed table
    | (any table when `allow` is empty) and no denied table. Queries touching
    | db-hound's own table are never captured.
    |
    */

    'tables' => [
        'allow' => [],
        'deny' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Samples
    |--------------------------------------------------------------------------
    |
    | Each Fingerprint keeps one Sample (its SQL with real bindings), encrypted
    | with the app key and used only to run EXPLAIN. The first Sample captured
    | is kept until it expires after `sample_ttl_days`; the next capture after
    | that replaces it. With `store_bindings` off no Sample (and so no binding
    | value) is stored at all: the next flush erases any stored before it was
    | turned off, and analysis ignores them. EXPLAIN then runs the Fingerprint
    | with placeholder values that match each column's type.
    |
    */

    'store_bindings' => (bool) env('DB_HOUND_STORE_BINDINGS', true),

    'sample_ttl_days' => (float) env('DB_HOUND_SAMPLE_TTL_DAYS', 7),

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
