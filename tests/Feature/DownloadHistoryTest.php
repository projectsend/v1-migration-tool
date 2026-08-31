<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\ActivityLogPhase;
use ProjectSend\V1Migration\Phases\DownloadsPhase;
use ProjectSend\V1Migration\Phases\FilesPhase;
use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

/**
 * One v1 download must become exactly one v2 activity row.
 *
 * v1 records a download in two places, and for a long time this importer
 * read both: `record_new_download()` writes a `tbl_downloads` row, and
 * the same request logs actions_log code 7/8 (37 when anonymous). Both
 * phases wrote the same v2 actions into the same column, so every
 * migrated install reported roughly twice the downloads it really had —
 * in v2's own product, not just in anything reading it from outside.
 *
 * The count is the assertion that matters, so it is made against the
 * *actions v2 counts*, not against a phase's own tally: the bug was
 * invisible to each phase individually and only existed in the total.
 */

beforeEach(function (): void {
    Storage::fake(FileTransfer::DISK);
});

/**
 * A v1 install where two files were downloaded three times between them,
 * with every download recorded the way v1 really records it — in both
 * tables at once.
 */
function importDownloadHistory(): MigrationContext
{
    $bundle = new BundleBuilder;
    $bundle->file('report.pdf', 'pdf bytes');
    $bundle->file('photo.jpg', 'jpg bytes');

    $bundle->table(V1Tables::FILES, [
        [
            'id' => 10, 'user_id' => 5, 'url' => 'report.pdf', 'original_url' => 'report.pdf',
            'filename' => 'Quarterly report', 'description' => null, 'size' => 9,
            'expires' => 0, 'public_allow' => 0, 'folder_id' => 0,
            'timestamp' => '2026-01-01 00:00:00',
        ],
        [
            'id' => 11, 'user_id' => 5, 'url' => 'photo.jpg', 'original_url' => 'photo.jpg',
            'filename' => 'Site photo', 'description' => null, 'size' => 9,
            'expires' => 0, 'public_allow' => 1, 'folder_id' => 0,
            'timestamp' => '2026-01-01 00:00:00',
        ],
    ]);

    // The downloads ledger: two by a signed-in client, one anonymous.
    $bundle->table(V1Tables::DOWNLOADS, [
        ['id' => 1, 'user_id' => 7, 'file_id' => 10, 'remote_ip' => '198.51.100.4', 'remote_host' => null, 'anonymous' => 0, 'timestamp' => '2026-02-01 10:00:00'],
        ['id' => 2, 'user_id' => 7, 'file_id' => 11, 'remote_ip' => '198.51.100.4', 'remote_host' => null, 'anonymous' => 0, 'timestamp' => '2026-02-01 11:00:00'],
        ['id' => 3, 'user_id' => 0, 'file_id' => 11, 'remote_ip' => '203.0.113.9', 'remote_host' => null, 'anonymous' => 1, 'timestamp' => '2026-02-02 09:00:00'],
    ]);

    // The audit trail for the very same three downloads — code 8 for a
    // client, 37 for the anonymous one — plus one login, which is a real
    // activity row and must survive.
    $bundle->table(V1Tables::ACTIONS_LOG, [
        ['id' => 1, 'action' => 8, 'owner_id' => 7, 'owner_user' => 'acme', 'affected_file' => 10, 'affected_file_name' => 'Quarterly report', 'affected_account' => 7, 'affected_account_name' => null, 'details' => null, 'timestamp' => '2026-02-01 10:00:00'],
        ['id' => 2, 'action' => 8, 'owner_id' => 7, 'owner_user' => 'acme', 'affected_file' => 11, 'affected_file_name' => 'Site photo', 'affected_account' => 7, 'affected_account_name' => null, 'details' => null, 'timestamp' => '2026-02-01 11:00:00'],
        ['id' => 3, 'action' => 37, 'owner_id' => 0, 'owner_user' => 'anonymous', 'affected_file' => 11, 'affected_file_name' => 'Site photo', 'affected_account' => 0, 'affected_account_name' => null, 'details' => null, 'timestamp' => '2026-02-02 09:00:00'],
        ['id' => 4, 'action' => 1, 'owner_id' => 7, 'owner_user' => 'acme', 'affected_file' => 0, 'affected_file_name' => null, 'affected_account' => 7, 'affected_account_name' => null, 'details' => null, 'timestamp' => '2026-02-01 09:00:00'],
    ]);

    $path = $bundle->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => ['checksums' => false],
    ]);

    $context = new MigrationContext($run, new BundleSource($path), new IdMap($run->id));

    // The downloader, already migrated. Inserted directly rather than
    // through UsersPhase so this test fails for download reasons only.
    $clientId = DB::table(HostTables::USERS)->insertGetId([
        'type' => 'client', 'name' => 'Acme Ltd', 'email' => 'acme@example.test',
        'password' => 'x', 'active' => true,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $context->idMap->record(MigrationIdMap::ENTITY_USER, 7, $clientId);
    $context->idMap->flush();

    (new FilesPhase)->chunk($context, 0);
    (new DownloadsPhase)->chunk($context, 0);
    (new ActivityLogPhase)->chunk($context, 0);
    $context->flushNotes();

    return $context;
}

/**
 * @return list<object>
 */
function importedDownloadRows(): array
{
    return DB::table(HostTables::ACTIVITY_LOG)
        ->whereIn('action', ['file.downloaded', 'public_file.downloaded', 'share_link.downloaded'])
        ->orderBy('created_at')
        ->get()
        ->all();
}

it('writes one activity row per real download, not one per place v1 recorded it', function (): void {
    importDownloadHistory();

    // Three downloads in v1. Six rows here is the bug: the downloads
    // table and the actions log each contributing their own copy.
    expect(importedDownloadRows())->toHaveCount(3);
});

it('keeps the download rows that carry the IP and the anonymous flag', function (): void {
    importDownloadHistory();

    $rows = importedDownloadRows();

    // The survivor has to be the downloads-table row. The log row it
    // replaced had no IP at all, so an install that kept the wrong one
    // of the two would still count correctly and quietly lose every
    // address on the downloads screen.
    expect(array_map(static fn (object $r): ?string => $r->ip_address, $rows))
        ->toBe(['198.51.100.4', '198.51.100.4', '203.0.113.9']);

    $anonymous = $rows[2];
    expect($anonymous->action)->toBe('public_file.downloaded')
        ->and($anonymous->origin)->toBe('public')
        ->and($anonymous->actor_id)->toBeNull();
});

it('snapshots the account and file names the downloads screen renders and filters on', function (): void {
    importDownloadHistory();

    $rows = importedDownloadRows();

    // v1's downloads table holds no names, so these are read from the
    // rows this migration already wrote. Without them v2 prints
    // "(deleted account)" for an account that is sitting right there,
    // and the file/user search boxes — which match on these columns
    // rather than joining — find nothing.
    expect(array_map(static fn (object $r): ?string => $r->actor_name, $rows))
        ->toBe(['Acme Ltd', 'Acme Ltd', null])
        ->and(array_map(static fn (object $r): ?string => $r->subject_name, $rows))
        ->toBe(['Quarterly report', 'Site photo', 'Site photo']);

    expect(array_map(static fn (object $r): ?string => $r->actor_type, $rows))
        ->toBe(['client', 'client', null]);
});

it('still imports the activity that is not a download', function (): void {
    importDownloadHistory();

    // The fix drops three codes, not the phase. A login sharing the
    // bundle with them has to come through untouched.
    $logins = DB::table(HostTables::ACTIVITY_LOG)->where('action', 'auth.login')->get();

    expect($logins)->toHaveCount(1)
        ->and($logins->first()->actor_name)->toBe('acme');
});

it('says in the report why the download codes were not carried across', function (): void {
    $context = importDownloadHistory();

    // A silent removal reads as an oversight to whoever next opens
    // ActionMap and sees three download codes missing.
    $report = json_encode($context->run->fresh()->report);

    expect($report)->toContain('downloads phase');
});
