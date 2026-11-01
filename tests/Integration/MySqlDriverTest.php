<?php

use Foodics\DbHound\Drivers\MySqlDriver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('hound_notes');
    Schema::create('hound_notes', function (Blueprint $table) {
        $table->id();
        $table->string('note');
    });
    DB::table('hound_notes')->insert(array_map(fn ($i) => ['note' => "n{$i}"], range(1, 200)));
});

afterEach(function () {
    if (DB::select("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'") !== []) {
        DB::statement('SET SESSION explain_json_format_version = 1');
    }
    Schema::dropIfExists('hound_notes');
});

it('detects a full scan when the server defaults to EXPLAIN JSON format version 2', function () {
    if (DB::select("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'") === []) {
        $this->markTestSkipped('This MySQL server has no explain_json_format_version (added in 8.3).');
    }

    DB::statement('SET SESSION explain_json_format_version = 2');

    $plan = (new MySqlDriver(DB::connection()))->explain('select * from `hound_notes` where `note` = ?', ['n1']);

    expect($plan->accesses)->toHaveCount(1)
        ->and($plan->accesses[0]->table)->toBe('hound_notes')
        ->and($plan->accesses[0]->isFullScan())->toBeTrue()
        ->and($plan->accesses[0]->rowsExamined)->toBeGreaterThan(0)
        ->and(DB::selectOne("SHOW SESSION VARIABLES LIKE 'explain_json_format_version'")->Value)->toBe('2');
});
