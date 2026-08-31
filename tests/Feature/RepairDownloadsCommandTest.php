<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Models\MigrationRun;

/**
 * The repair for installs migrated before the double-count fix.
 *
 * These are written mostly about what it must *not* do. It deletes from
 * an audit log, and the failure that matters is not "missed a duplicate"
 * — it is "removed something real", which nobody notices until the
 * history is needed.
 */
function migrationRun(string $startedAt = '2026-06-01 12:00:00', int $baseline = 0): MigrationRun
{
    return MigrationRun::create([
        'status' => MigrationRun::STATUS_COMPLETED,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => '/tmp/none'],
        'options' => [],
        'started_at' => $startedAt,
        'report' => ['baseline' => [HostTables::ACTIVITY_LOG => $baseline]],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function activityRow(array $overrides = []): int
{
    return DB::table(HostTables::ACTIVITY_LOG)->insertGetId(array_merge([
        'actor_id' => null,
        'actor_name' => null,
        'actor_type' => 'client',
        'origin' => 'ui',
        'ip_address' => null,
        'action' => 'file.downloaded',
        'subject_type' => HostTables::MORPH_FILE,
        'subject_id' => 1,
        'subject_name' => null,
        'context' => null,
        'created_at' => '2026-02-01 10:00:00',
    ], $overrides));
}

function downloadRowCount(): int
{
    return DB::table(HostTables::ACTIVITY_LOG)
        ->whereIn('action', ['file.downloaded', 'public_file.downloaded'])
        ->count();
}

it('removes the log copy and keeps the ledger copy', function (): void {
    $run = migrationRun();

    // The pair an old import left: the ledger row (no subject_name, but
    // carrying the IP) and the actions_log row describing the same
    // download at the same instant.
    activityRow(['ip_address' => '198.51.100.4']);
    activityRow(['subject_name' => 'Quarterly report', 'actor_name' => 'acme']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->assertSuccessful();

    $rows = DB::table(HostTables::ACTIVITY_LOG)->get();

    expect($rows)->toHaveCount(1)
        // The survivor has to be the ledger row: it is the one carrying
        // the address, and keeping the wrong one would count correctly
        // while losing every IP on the downloads screen.
        ->and($rows->first()->ip_address)->toBe('198.51.100.4');
});

it('does nothing at all on an install migrated after the fix', function (): void {
    // The trap this command exists to avoid. The same fix that stopped
    // the duplication also made DownloadsPhase write subject_name, so
    // "a migrated download row with a subject_name" now matches every
    // download row there is. Matching on shape alone would delete the
    // entire history of a perfectly healthy install.
    $run = migrationRun();

    activityRow(['subject_name' => 'Quarterly report', 'ip_address' => '198.51.100.4']);
    activityRow(['subject_name' => 'Site photo', 'subject_id' => 2, 'ip_address' => '198.51.100.5']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->expectsOutputToContain('Nothing to repair')
        ->assertSuccessful();

    expect(downloadRowCount())->toBe(2);
});

it('keeps a log row whose download has no ledger counterpart', function (): void {
    // DownloadsPhase skips a download whose file was not imported, so
    // for those the actions_log row is the only record there is. Having
    // no partner, it must survive.
    $run = migrationRun();

    activityRow(['subject_name' => 'Deleted long ago', 'subject_id' => null]);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->assertSuccessful();

    expect(downloadRowCount())->toBe(1);
});

it('never touches a download the installation recorded after the import', function (): void {
    // A real v2 download carries today's timestamp. However closely its
    // columns resemble an imported row, it is outside the window the
    // run could have written in.
    $run = migrationRun(startedAt: '2026-06-01 12:00:00');

    activityRow(['ip_address' => '198.51.100.4']);
    activityRow(['subject_name' => 'Quarterly report']);
    // Same file, but downloaded through v2 a month after the migration.
    activityRow(['subject_name' => 'Quarterly report', 'created_at' => '2026-07-04 08:00:00']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->assertSuccessful();

    $remaining = DB::table(HostTables::ACTIVITY_LOG)->orderBy('id')->get();

    expect($remaining)->toHaveCount(2)
        ->and($remaining->last()->created_at)->toContain('2026-07-04');
});

it('never touches a row that was there before the import', function (): void {
    $existing = activityRow(['subject_name' => 'Pre-existing', 'created_at' => '2026-01-01 00:00:00']);

    // Everything at or below this id was already here.
    $run = migrationRun(baseline: $existing);

    activityRow(['ip_address' => '198.51.100.4']);
    activityRow(['subject_name' => 'Quarterly report']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->assertSuccessful();

    expect(DB::table(HostTables::ACTIVITY_LOG)->find($existing))->not->toBeNull()
        ->and(downloadRowCount())->toBe(2);
});

it('reports and changes nothing unless it is asked to repair', function (): void {
    $run = migrationRun();

    activityRow();
    activityRow(['subject_name' => 'Quarterly report']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id])
        ->expectsOutputToContain('Duplicates to remove:')
        ->expectsOutputToContain('Re-run with --repair')
        ->assertSuccessful();

    expect(downloadRowCount())->toBe(2);
});

it('is safe to run twice', function (): void {
    $run = migrationRun();

    activityRow();
    activityRow(['subject_name' => 'Quarterly report']);

    $args = ['--run' => (string) $run->id, '--repair' => true, '--force' => true];

    $this->artisan('projectsend:migrate:repair-downloads', $args)->assertSuccessful();
    $this->artisan('projectsend:migrate:repair-downloads', $args)
        ->expectsOutputToContain('Nothing to repair')
        ->assertSuccessful();

    expect(downloadRowCount())->toBe(1);
});

it('refuses a run whose baseline was never recorded', function (): void {
    // Without one there is no way to tell what the run created, and
    // guessing is exactly the wrong instinct on an audit log.
    $run = migrationRun();
    $run->update(['report' => ['preflight' => []]]);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->expectsOutputToContain('no safe way')
        ->assertFailed();
});

it('leaves everything alone when the operator says no', function (): void {
    $run = migrationRun();

    activityRow();
    activityRow(['subject_name' => 'Quarterly report']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true])
        ->expectsConfirmation('Permanently remove 1 duplicate download row(s)?', 'no')
        ->assertFailed();

    expect(downloadRowCount())->toBe(2);
});

it('matches the anonymous pair on its own action, not across actions', function (): void {
    $run = migrationRun();

    // A public download and a signed-in one, same file, same instant.
    // Pairing must respect the action or these would cancel each other.
    activityRow(['action' => 'public_file.downloaded', 'actor_type' => null]);
    activityRow(['action' => 'public_file.downloaded', 'actor_type' => null, 'subject_name' => 'Site photo']);
    activityRow(['subject_name' => 'Site photo']);

    $this->artisan('projectsend:migrate:repair-downloads', ['--run' => (string) $run->id, '--repair' => true, '--force' => true])
        ->assertSuccessful();

    $remaining = DB::table(HostTables::ACTIVITY_LOG)->get();

    // The public pair collapses to one. The lone file.downloaded row has
    // no ledger partner of its own action, so it stays.
    expect($remaining)->toHaveCount(2)
        ->and($remaining->where('action', 'public_file.downloaded'))->toHaveCount(1)
        ->and($remaining->where('action', 'file.downloaded'))->toHaveCount(1);
});
