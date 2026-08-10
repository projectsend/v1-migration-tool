<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

function bundleOfUsers(int $count): string
{
    $rows = [];
    for ($id = 1; $id <= $count; $id++) {
        $rows[] = ['id' => $id, 'user' => "user{$id}", 'email' => "user{$id}@example.com"];
    }

    return (new BundleBuilder)->table(V1Tables::USERS, $rows)->write();
}

it('refuses a directory that is not an export bundle', function (): void {
    new BundleSource(sys_get_temp_dir());
})->throws(RuntimeException::class, 'not a ProjectSend v1 export bundle');

it('reads a table in pages, ordered by id', function (): void {
    $source = new BundleSource(bundleOfUsers(10));

    $first = $source->rows(V1Tables::USERS, 0, 4);
    $second = $source->rows(V1Tables::USERS, 4, 4);
    $third = $source->rows(V1Tables::USERS, 8, 4);

    expect(array_column($first, 'id'))->toBe([1, 2, 3, 4])
        ->and(array_column($second, 'id'))->toBe([5, 6, 7, 8])
        ->and(array_column($third, 'id'))->toBe([9, 10])
        ->and($source->rows(V1Tables::USERS, 10, 4))->toBe([]);
});

it('picks up mid-table when a run resumes', function (): void {
    // A recycled worker starts a fresh process with nothing open. gzip
    // cannot seek, so the reader has to scan forward — and must not
    // lose the first row past the cursor while doing it.
    $source = new BundleSource(bundleOfUsers(10));

    $rows = $source->rows(V1Tables::USERS, 6, 3);

    expect(array_column($rows, 'id'))->toBe([7, 8, 9]);
});

it('keeps reading forward after a resumed page', function (): void {
    $source = new BundleSource(bundleOfUsers(10));

    $source->rows(V1Tables::USERS, 6, 2);

    expect(array_column($source->rows(V1Tables::USERS, 8, 2), 'id'))->toBe([9, 10]);
});

it('returns nothing for a table the bundle does not carry', function (): void {
    $source = new BundleSource(bundleOfUsers(1));

    expect($source->rows(V1Tables::INTEGRATIONS))->toBe([])
        ->and($source->count(V1Tables::INTEGRATIONS))->toBe(0);
});

it('lets the last row win when v1 stored an option name twice', function (): void {
    // tbl_options has no unique key on `name`, and duplicate rows do
    // occur — written straight out of a settings form post.
    $path = (new BundleBuilder)->table(V1Tables::OPTIONS, [
        ['id' => 1, 'name' => 'this_install_title', 'value' => 'Old name'],
        ['id' => 2, 'name' => 'clients_can_register', 'value' => '1'],
        ['id' => 3, 'name' => 'this_install_title', 'value' => 'New name'],
    ])->write();

    expect((new BundleSource($path))->options())->toBe([
        'this_install_title' => 'New name',
        'clients_can_register' => '1',
    ]);
});

it('can be asked for options twice', function (): void {
    // The options reader hits EOF on the first pass; a second phase
    // asking again must not get an empty array.
    $source = new BundleSource((new BundleBuilder)->table(V1Tables::OPTIONS, [
        ['id' => 1, 'name' => 'this_install_title', 'value' => 'Studio'],
    ])->write());

    expect($source->options())->toBe($source->options());
});

it('describes itself from the manifest', function (): void {
    $path = (new BundleBuilder)
        ->table(V1Tables::FILES, [['id' => 1]])
        ->manifest([
            'uploads_organized_by_date' => true,
            'has_encryption_key' => true,
            'warnings' => ['14 file rows have no bytes on disk'],
        ])
        ->write();

    $manifest = (new BundleSource($path))->manifest();

    expect($manifest->kind)->toBe('bundle')
        ->and($manifest->version)->toBe('r2098')
        ->and($manifest->uploadsOrganizedByDate)->toBeTrue()
        ->and($manifest->hasEncryptionKey)->toBeTrue()
        ->and($manifest->counts[V1Tables::FILES])->toBe(1)
        ->and($manifest->warnings)->toBe(['14 file rows have no bytes on disk'])
        // No payload directory was written, so the bytes are elsewhere.
        ->and($manifest->filesIncluded)->toBeFalse();
});

it('serves file bytes when the bundle carries them', function (): void {
    $path = (new BundleBuilder)
        ->table(V1Tables::FILES, [['id' => 1]])
        ->file('2026/03/report.pdf', 'pdf bytes')
        ->write();

    $source = new BundleSource($path);

    expect($source->fileExists('2026/03/report.pdf'))->toBeTrue()
        ->and($source->fileSize('2026/03/report.pdf'))->toBe(9)
        ->and($source->fileExists('2026/03/missing.pdf'))->toBeFalse()
        ->and($source->manifest()->filesIncluded)->toBeTrue();
});

it('refuses to resolve a path outside the bundle', function (): void {
    $source = new BundleSource(bundleOfUsers(1));

    // v1's on-disk names come from a text column, and the directory part
    // is derived from two integers. Neither is trusted.
    expect($source->filePath('../../etc/passwd'))->toBeNull()
        ->and($source->filePath(''))->toBeNull();
});
