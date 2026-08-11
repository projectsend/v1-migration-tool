<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ProjectSend\V1Migration\Host\Baseline;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Models\MigrationRun;

it('clears a run that never started importing', function (): void {
    // Refused at preflight, or still waiting to be acknowledged: nothing
    // was written, so there is no baseline — and demanding one would
    // leave the run stuck on the screen with no way to dismiss it.
    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_BLOCKED,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => [],
        'options' => [],
        'report' => ['preflight' => []],
    ]);

    $this->artisan('projectsend:migrate:reset', ['--force' => true])
        ->expectsOutputToContain('never started importing')
        ->assertSuccessful();

    expect(MigrationRun::query()->count())->toBe(0);
});

it('refuses a run that wrote things but has no baseline', function (): void {
    Storage::fake('files');

    // The dangerous case the check exists for: something was imported and
    // there is no record of what was there before it.
    MigrationRun::create([
        'status' => MigrationRun::STATUS_COMPLETED,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => [],
        'options' => [],
        'report' => [],
        'started_at' => now(),
    ]);

    $this->artisan('projectsend:migrate:reset', ['--force' => true])
        ->expectsOutputToContain('no baseline recorded')
        ->assertFailed();

    expect(MigrationRun::query()->count())->toBe(1);
});

it('deletes what a run created and leaves what was already there', function (): void {
    // The `files` disk is the host's; there is no host here.
    Storage::fake('files');

    $before = DB::table(HostTables::CATEGORIES)->insertGetId([
        'name' => 'Already here', 'color' => 'gray', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_COMPLETED,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => [],
        'options' => [],
        'report' => ['baseline' => [HostTables::CATEGORIES => $before]],
        'started_at' => now(),
    ]);

    $imported = DB::table(HostTables::CATEGORIES)->insertGetId([
        'name' => 'Imported', 'color' => 'gray', 'created_at' => now(), 'updated_at' => now(),
    ]);

    MigrationIdMap::create([
        'run_id' => $run->id,
        'entity' => MigrationIdMap::ENTITY_CATEGORY,
        'source_id' => 1,
        'target_id' => $imported,
        'status' => MigrationIdMap::STATUS_IMPORTED,
    ]);

    $this->artisan('projectsend:migrate:reset', ['--force' => true])->assertSuccessful();

    expect(DB::table(HostTables::CATEGORIES)->pluck('name')->all())->toBe(['Already here'])
        ->and(MigrationIdMap::query()->count())->toBe(0)
        ->and(MigrationRun::query()->count())->toBe(0);
});

it('says there is nothing to undo when no run exists', function (): void {
    $this->artisan('projectsend:migrate:reset', ['--force' => true])
        ->expectsOutputToContain('nothing to undo')
        ->assertSuccessful();
});

// The other half of the same bug: undoing a run on a host without the
// optional table must not try to delete from it. Failing there would
// strand somebody mid-undo, which is the one moment this tool has to work.
it('undoes a run on a host that never had the optional table', function (): void {
    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_COMPLETED,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => [],
        'options' => [],
        'report' => ['baseline' => Baseline::capture()],
    ]);

    Schema::drop(HostTables::CAPTCHA_PROVIDERS);

    $this->artisan('projectsend:migrate:reset', ['--force' => true])->assertSuccessful();

    expect(MigrationRun::find($run->id))->toBeNull();
});
