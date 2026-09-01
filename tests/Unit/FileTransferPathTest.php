<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Source\MigrationSource;

/**
 * The storage path this tool writes into v2's `files.path`.
 *
 * Its extension is the one part not generated here: it comes from a v1
 * database, which is somebody else's data, and v2 writes the whole path
 * into a response header — X-Accel-Redirect or X-Sendfile, depending on
 * the web server. A CR or LF in a header value is header injection, and
 * the host now refuses such a path outright, so a file imported with one
 * would download as a 404 forever.
 */
function targetPathFor(string $sourcePath): string
{
    // targetPath() touches neither the source nor the disk, so the
    // source is a stub rather than a staged bundle.
    $source = Mockery::mock(MigrationSource::class);

    return (new FileTransfer($source))->targetPath($sourcePath, '2026-02-01 10:00:00');
}

it('keeps an ordinary extension', function (): void {
    expect(targetPathFor('1699999999-abc-report.PDF'))->toEndWith('.pdf')
        ->and(targetPathFor('photo.jpeg'))->toEndWith('.jpeg')
        ->and(targetPathFor('archive.7z'))->toEndWith('.7z');
});

it('refuses to carry a control character out of a v1 filename', function (): void {
    $path = targetPathFor("report.pd\r\nX-Injected: yes");

    expect($path)->not->toMatch('/[\x00-\x1F\x7F]/')
        ->and($path)->toEndWith('.pdxinjectedyes');
});

it('leaves nothing in the extension that could be a path of its own', function (): void {
    // A separator here would put the file somewhere else entirely, and a
    // dot pair would climb.
    expect(targetPathFor('x.../..'))->not->toContain('..')
        ->and(targetPathFor('x.a/b'))->not->toContain('a/b')
        ->and(substr_count(targetPathFor('x.a/b'), '/'))->toBe(2);
});

it('still produces a bare path when the source has no extension at all', function (): void {
    expect(targetPathFor('no-extension-here'))->toMatch('#^2026/02/[0-9a-f-]{36}$#')
        // An extension of nothing but punctuation reduces to nothing, and
        // must not leave a trailing dot behind.
        ->and(targetPathFor('weird.--'))->toMatch('#^2026/02/[0-9a-f-]{36}$#');
});
