<?php

use Foodics\DbHound\Analysis\AnalysisResult;
use Foodics\DbHound\Analysis\Index;
use Foodics\DbHound\Analysis\Plan;
use Foodics\DbHound\Analysis\Suggestion;
use Foodics\DbHound\Analysis\TableAccess;
use Foodics\DbHound\Analysis\UnparsedQuery;
use Foodics\DbHound\Report\ReportContext;
use Foodics\DbHound\Report\ReportRenderer;
use Illuminate\Support\Carbon;

/**
 * Every configurable key of a config array, dotted: a list or an empty array
 * is one key (its value is the setting), a non-empty associative array is a
 * section whose own keys are configured.
 *
 * @param  array<array-key, mixed>  $config
 * @return list<string>
 */
function configKeys(array $config, string $prefix = ''): array
{
    $keys = [];

    foreach ($config as $key => $value) {
        $dotted = $prefix.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            array_push($keys, ...configKeys($value, $dotted.'.'));
        } else {
            $keys[] = $dotted;
        }
    }

    return $keys;
}

/**
 * The README configuration reference, keyed by dotted config key: its
 * environment variable (null for `none`) and its documented default (the
 * first backticked literal of the Default column).
 *
 * @return array<string, array{env: ?string, default: string}>
 */
function documentedConfig(): array
{
    preg_match_all('/^\| `([a-z_.]+)` \| (`(DB_HOUND_[A-Z0-9_]+)`|none) \| `([^`]+)`[^|]* \|/m', readmeSection('Configuration reference'), $rows, PREG_SET_ORDER);

    $documented = [];
    foreach ($rows as $row) {
        $documented[$row[1]] = ['env' => $row[3] === '' ? null : $row[3], 'default' => $row[4]];
    }

    return $documented;
}

/**
 * The published config as it loads with `$env` set and every other
 * DB_HOUND_* variable unset, so each key holds its own default.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function publishedConfig(array $env = []): array
{
    $saved = [$_SERVER, $_ENV, getenv()];

    foreach (array_keys($saved[2] + $_SERVER + $_ENV) as $name) {
        if (str_starts_with((string) $name, 'DB_HOUND_')) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv((string) $name);
        }
    }
    foreach ($env as $name => $value) {
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    try {
        return require __DIR__.'/../../config/db-hound.php';
    } finally {
        [$_SERVER, $_ENV] = $saved;
        foreach (array_keys($env) as $name) {
            putenv($name);
        }
        foreach ($saved[2] as $name => $value) {
            if (str_starts_with((string) $name, 'DB_HOUND_')) {
                putenv("{$name}={$value}");
            }
        }
    }
}

/**
 * Two config values are the same setting: numbers compare by value (the
 * README writes `0` for the float `0.0`), anything else strictly.
 */
function sameSetting(mixed $documented, mixed $actual): bool
{
    return is_int($documented) || is_float($documented)
        ? (is_int($actual) || is_float($actual)) && (float) $documented === (float) $actual
        : $documented === $actual;
}

it('documents every published config key in the README configuration reference', function () {
    $keys = configKeys(publishedConfig());

    $undocumented = array_values(array_diff($keys, array_keys(documentedConfig())));

    expect($keys)->toContain('enabled', 'redis.timeout', 'analysis.ignore.tables', 'report.directory')
        ->and($undocumented)->toBe([]);
});

it('documents each config key with the default the published config gives it', function () {
    $config = publishedConfig();

    $wrong = [];
    foreach (documentedConfig() as $key => ['default' => $literal]) {
        // The README's defaults are PHP literals (or `storage_path(...)`), the way the config file writes them.
        $documented = eval("return {$literal};");

        if (! sameSetting($documented, data_get($config, $key))) {
            $wrong[$key] = $literal;
        }
    }

    expect(documentedConfig())->toHaveKeys(['sample_ttl_days', 'flush.max_bytes', 'file.path', 'analysis.ignore.tables'])
        ->and($wrong)->toBe([]);
});

it('documents each environment variable in the row of the config key it sets', function () {
    preg_match_all("/env\\('(DB_HOUND_[A-Z0-9_]+)'/", (string) file_get_contents(__DIR__.'/../../config/db-hound.php'), $matches);
    $variables = array_values(array_unique($matches[1]));
    $documented = array_filter(documentedConfig(), fn (array $row) => $row['env'] !== null);

    $defaults = publishedConfig();
    $misplaced = [];
    foreach ($documented as $key => ['env' => $variable]) {
        $default = data_get($defaults, $key);
        $value = match (true) {
            is_bool($default) => $default ? 'false' : 'true',
            is_int($default), is_float($default) => '12345',
            default => 'documented-env-check',
        };

        if (sameSetting(data_get(publishedConfig([$variable => $value]), $key), $default)) {
            $misplaced[$key] = $variable;
        }
    }

    expect($variables)->toContain('DB_HOUND_ENABLED')
        ->and(array_values(array_diff($variables, array_column($documented, 'env'))))->toBe([])
        ->and($misplaced)->toBe([]);
});

it('runs every step of the kubectl examples against one pod', function () {
    preg_match_all('/^kubectl (exec|cp) .*$/m', readmeSection('Copying files off the host'), $steps);
    // The pod follows `exec -n <namespace>`, or `cp <namespace>/` up to the `:`.
    preg_match_all('/(?:exec -n \S+ |cp "?[^\/\s]+\/)"?([^\s:"]+)/', implode("\n", $steps[0]), $pods);

    expect($steps[1])->toContain('exec', 'cp')
        ->and($pods[1])->toHaveCount(count($steps[0]))
        ->and(array_values(array_unique($pods[1])))->toBe(['$POD']);
});

it('documents every field of the Report', function () {
    $suggestion = new Suggestion(
        connection: 'mysql',
        table: 'orders',
        columns: ['branch_id', 'status', 'created_at'],
        reasons: ['no index used', 'filesort'],
        rowCount: 182340,
        existingIndexes: [new Index('orders_branch_id_index', ['branch_id'], false)],
        columnTypes: ['branch_id' => 'bigint unsigned', 'status' => 'varchar(20)', 'created_at' => 'timestamp'],
        access: new TableAccess('orders', 'ref', 'orders_branch_id_index', 48211),
        plan: new Plan([new TableAccess('orders', 'ref', 'orders_branch_id_index', 48211)], usingFilesort: true),
    );
    $suggestion->extends = $suggestion->existingIndexes[0];
    $suggestion->flag([], 'filesort remains; consider `(branch_id, created_at, status)` if the range on `status` matches most rows.');
    $suggestion->serve('select * from `orders` where `branch_id` = ? and `status` in (?) order by `created_at` desc', 1200, 50000.0, 310.5);

    $markdown = (new ReportRenderer)->render(new ReportContext(
        generatedAt: Carbon::parse('2026-11-01 10:00:00', 'UTC'),
        connections: ['mysql'],
        firstSeen: Carbon::parse('2026-10-02 08:15:00', 'UTC'),
        lastSeen: Carbon::parse('2026-11-01 09:59:00', 'UTC'),
        fingerprints: 4,
        queries: 1250,
        thresholds: everyFingerprint()->describe(),
    ), new AnalysisResult([$suggestion], [new UnparsedQuery('mysql', 'select 1', 'unparseable')]));

    preg_match_all('/^#{2,3} ([A-Z][a-z][^\n]*)$/m', $markdown, $sections);
    preg_match_all('/^- \*\*([^*]+):\*\*/m', $markdown, $fields);
    preg_match_all('/^\*\*([^*`(]+?)\s*(?:`|\(|\*\*)/m', $markdown, $blocks);
    preg_match_all('/^  - ([^:]+):/m', $markdown, $thresholds);

    $documentation = readmeSection('Report format');
    $undocumented = array_values(array_filter([
        ...array_map(fn (string $section) => "## {$section}", $sections[1]),
        ...array_map(fn (string $label) => "**{$label}**", $fields[1]),
        // Block headings end with the table or a count: `Existing indexes on `orders``, `Served Fingerprints (2)`.
        ...array_map(fn (string $label) => "**{$label}", $blocks[1]),
        ...$thresholds[1],
    ], fn (string $text) => ! str_contains($documentation, $text)));

    expect($fields[1])->toContain('Generated at', 'Kind', 'Note')
        ->and($blocks[1])->toContain('EXPLAIN summary', 'Existing indexes on', 'Served Fingerprints')
        ->and($thresholds[1])->toContain('Fingerprint count')
        ->and($undocumented)->toBe([]);
});
