<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\FilesPhase;
use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

/**
 * v1's download limits, which v2 gained a home for.
 *
 * Before that it reported them as something it could not carry, and a
 * customer migrating a file they meant to release three times got an
 * unlimited one. These assert the pair actually survives — including
 * the two v1 states the enum on the other side cannot represent.
 *
 * @param  list<array<string, mixed>>  $files
 */
function importFiles(array $files): MigrationContext
{
    $bundle = new BundleBuilder;

    $rows = [];
    foreach ($files as $index => $file) {
        $name = 'doc-'.($index + 1).'.pdf';
        $bundle->file($name, 'pdf bytes');

        $rows[] = array_merge([
            'id' => $index + 1,
            'user_id' => 0,
            'url' => $name,
            'original_url' => $name,
            'filename' => 'Document '.($index + 1),
            'description' => null,
            'size' => 9,
            'expires' => 0,
            'public_allow' => 0,
            'folder_id' => 0,
            'timestamp' => '2026-01-01 00:00:00',
        ], $file);
    }

    $path = $bundle->table(V1Tables::FILES, $rows)->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => ['checksums' => false],
    ]);

    $context = new MigrationContext($run, new BundleSource($path), new IdMap($run->id));

    (new FilesPhase)->chunk($context, 0);
    $context->flushNotes();

    return $context;
}

/**
 * @return list<array{download_limit: int|null, download_limit_scope: string}>
 */
function importedLimits(): array
{
    return DB::table(HostTables::FILES)
        ->orderBy('id')
        ->get(['download_limit', 'download_limit_scope'])
        ->map(static fn (object $row): array => [
            'download_limit' => $row->download_limit === null ? null : (int) $row->download_limit,
            'download_limit_scope' => $row->download_limit_scope,
        ])
        ->all();
}

beforeEach(function (): void {
    Storage::fake(FileTransfer::DISK);
});

it('carries both kinds of download limit across', function (): void {
    importFiles([
        ['download_limit_enabled' => 1, 'download_limit_type' => 'total', 'download_limit_count' => 3],
        ['download_limit_enabled' => 1, 'download_limit_type' => 'per_user', 'download_limit_count' => 5],
    ]);

    expect(importedLimits())->toBe([
        ['download_limit' => 3, 'download_limit_scope' => 'total'],
        ['download_limit' => 5, 'download_limit_scope' => 'per_user'],
    ]);
});

it('leaves a file unlimited when v1 had the limit switched off', function (): void {
    // v1's count column holds a number whether or not the limit is on,
    // exactly as its expiry_date does — reading it without the flag
    // would cap every file in the install.
    importFiles([
        ['download_limit_enabled' => 0, 'download_limit_type' => 'per_user', 'download_limit_count' => 4],
    ]);

    expect(importedLimits())->toBe([
        ['download_limit' => null, 'download_limit_scope' => 'total'],
    ]);
});

it('drops a limit that was enabled without a count, rather than blocking the file forever', function (): void {
    $context = importFiles([
        ['download_limit_enabled' => 1, 'download_limit_type' => 'total', 'download_limit_count' => 0],
    ]);

    expect(importedLimits())->toBe([
        ['download_limit' => null, 'download_limit_scope' => 'total'],
    ]);

    expect($context->run->refresh()->report['files'] ?? [])
        ->toHaveKey('download_limits_enabled_without_a_count');
});

it('falls back to a total limit when v1 holds a scope v2 does not have', function (): void {
    // The column is an unconstrained varchar(20) in v1, and an install
    // that has been through a decade of upgrades can hold anything.
    $context = importFiles([
        ['download_limit_enabled' => 1, 'download_limit_type' => 'per_ip', 'download_limit_count' => 2],
    ]);

    expect(importedLimits())->toBe([
        ['download_limit' => 2, 'download_limit_scope' => 'total'],
    ]);

    expect($context->run->refresh()->report['files'] ?? [])
        ->toHaveKey('download_limits_with_an_unknown_scope');
});
