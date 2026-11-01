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
    | Capture fails open: db-hound opens its own connection with that config
    | but with `timeout` seconds to connect and to wait for each reply, so a
    | slow or unreachable Redis never blocks a request for long. A batch that
    | can't be written is dropped and logged; for the next minute that process
    | then drops its batches without contacting Redis, so an outage costs it
    | one timeout (and one log entry) per minute.
    |
    */

    'redis' => [
        'connection' => env('DB_HOUND_REDIS_CONNECTION', 'default'),
        'prefix' => env('DB_HOUND_REDIS_PREFIX', 'db-hound:'),
        'timeout' => (float) env('DB_HOUND_REDIS_TIMEOUT', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flush
    |--------------------------------------------------------------------------
    |
    | The package schedules `db-hound:flush` every minute while capture is
    | enabled; set `schedule` to false to register it yourself instead. Each
    | run moves the buffer into `db_hound_queries` only once it holds at least
    | `max_fingerprints` Fingerprints or `max_bytes` bytes (approximate size
    | of the buffered SQL and Samples), or once its oldest capture is
    | `max_age_minutes` old, so quiet apps don't lose data to Redis eviction.
    | `db-hound:flush --force` flushes regardless.
    |
    */

    'flush' => [
        'schedule' => (bool) env('DB_HOUND_FLUSH_SCHEDULE', true),
        'max_fingerprints' => (int) env('DB_HOUND_FLUSH_MAX_FINGERPRINTS', 5000),
        'max_bytes' => (int) env('DB_HOUND_FLUSH_MAX_BYTES', 10 * 1024 * 1024),
        'max_age_minutes' => (float) env('DB_HOUND_FLUSH_MAX_AGE_MINUTES', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `db-hound:prune` deletes Fingerprints from `db_hound_queries` that were
    | not seen for `prune_after_days` days, so removed code paths drop out of
    | Reports. This is the only time window: `db-hound:analyze` considers
    | every Fingerprint still in the table, so lower it for fresher Reports.
    | Rows are deleted at most `prune.chunk_size` per statement to keep locks
    | short. The package schedules the prune daily once the table exists,
    | and it keeps running after capture is turned off so Reports never reach
    | further back than this; set `prune.schedule` to false to register it
    | yourself instead.
    |
    */

    'prune_after_days' => (float) env('DB_HOUND_PRUNE_AFTER_DAYS', 30),

    'prune' => [
        'schedule' => (bool) env('DB_HOUND_PRUNE_SCHEDULE', true),
        'chunk_size' => (int) env('DB_HOUND_PRUNE_CHUNK_SIZE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis
    |--------------------------------------------------------------------------
    |
    | `db-hound:analyze` explains a Fingerprint only when it ran at least
    | `min_count` times AND its average took at least `min_avg_ms` OR its
    | slowest run at least `min_max_ms`. Of those, at most `max_fingerprints`
    | with the most impact (count × avg ms) are explained per run. Tables with
    | fewer than `min_table_rows` rows (information_schema.TABLES estimate) get
    | no Suggestion: MySQL scans them whatever their indexes.
    |
    | Fingerprints are never explained when they touch an `ignore.tables`
    | table (names without the connection's table prefix; `*` wildcards
    | allowed; db-hound's own table is always ignored), start with an
    | `ignore.statements` keyword, or read a table of an `ignore.schemas`
    | schema.
    |
    | EXPLAIN and schema inspection run on the captured connection, or on the
    | connection `connections` maps it to (e.g. `'mysql' => 'mysql_replica'`
    | to analyze on a read replica). Only SELECTs are explained (an UPDATE or
    | DELETE as the SELECT of the rows it touches), with a plain
    | `EXPLAIN FORMAT=JSON`, inside a read-only transaction whose session
    | `max_execution_time` is `max_execution_time_ms`.
    |
    */

    'analysis' => [
        'connections' => [
            // 'mysql' => 'mysql_replica',
        ],
        'max_execution_time_ms' => (int) env('DB_HOUND_ANALYSIS_MAX_EXECUTION_TIME_MS', 5000),
        'min_count' => (int) env('DB_HOUND_ANALYSIS_MIN_COUNT', 10),
        'min_avg_ms' => (float) env('DB_HOUND_ANALYSIS_MIN_AVG_MS', 50),
        'min_max_ms' => (float) env('DB_HOUND_ANALYSIS_MIN_MAX_MS', 500),
        'max_fingerprints' => (int) env('DB_HOUND_ANALYSIS_MAX_FINGERPRINTS', 200),
        'min_table_rows' => (int) env('DB_HOUND_ANALYSIS_MIN_TABLE_ROWS', 1000),

        'ignore' => [
            'tables' => [
                'migrations',
                'jobs',
                'job_batches',
                'failed_jobs',
                'sessions',
                'cache',
                'cache_locks',
                'telescope_*',
            ],
            'statements' => ['SHOW', 'SET'],
            'schemas' => ['information_schema', 'performance_schema'],
        ],
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
