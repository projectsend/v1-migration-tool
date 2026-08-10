<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Files;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProjectSend\V1Migration\Source\MigrationSource;
use RuntimeException;

/**
 * Gets a v1 file's bytes to where v2 expects them.
 *
 * ### Why the path changes
 *
 * v1 stores files either flat in `upload/files` or under `YYYY/MM`,
 * named `{timestamp}-{16 hex}-{sanitised original}.{ext}`. v2 uses
 * `Y/m/{uuid}.{ext}` on its `files` disk. Rewriting to v2's convention
 * rather than adopting v1's costs nothing (the name is only ever read
 * out of the database) and means an imported install looks exactly like
 * a native one — the flat/dated split disappears, and the original
 * filename stops being visible in a URL path.
 *
 * ### Why not just point v2 at v1's directory
 *
 * Because downloads would 404. v2 serves files through nginx
 * `X-Accel-Redirect` from an `internal` location aliasing
 * `storage/app/files/`; a disk rooted anywhere else is unreachable no
 * matter what the database says.
 *
 * ### The strategies
 *
 * `hardlink` is the answer to the 400 GB install: a second directory
 * entry for the same inode, created in microseconds, consuming no extra
 * disk. It only works within one filesystem, so it falls back to copying
 * across a boundary rather than failing. It leaves v1 completely intact,
 * which matters — until the operator is satisfied, both installs point
 * at the same bytes and either can be thrown away.
 *
 * `copy` is the default because it is the only one that is always
 * correct, and it computes the checksum from the same stream it writes,
 * so verification is free.
 *
 * `move` is for the operator who has already decided, and is destructive
 * to the source.
 *
 * `defer` writes no bytes at all. It is for migrating a large install in
 * two sittings: import the database now, rsync half a terabyte
 * overnight, attach the bytes afterwards.
 */
final class FileTransfer
{
    public const HARDLINK = 'hardlink';

    public const COPY = 'copy';

    public const MOVE = 'move';

    public const DEFER = 'defer';

    public const DISK = 'files';

    public function __construct(
        private readonly MigrationSource $source,
        private readonly string $strategy = self::COPY,
        private readonly bool $checksums = true,
    ) {}

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return [self::HARDLINK, self::COPY, self::MOVE, self::DEFER];
    }

    /**
     * v2's path for a file, derived from when v1 received it.
     */
    public function targetPath(string $sourcePath, ?string $uploadedAt): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $timestamp = $uploadedAt !== null ? strtotime($uploadedAt) : false;
        $prefix = date('Y/m', $timestamp === false ? time() : $timestamp);

        return $prefix.'/'.Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Move one file's bytes into place.
     *
     * @return array{size: int, checksum: string, mime: string}|null
     *         null when the bytes are not there — a v1 row whose file was
     *         deleted from disk, which is common enough that `ps-messy`
     *         has fourteen of them on purpose.
     */
    public function transfer(string $sourcePath, string $targetPath): ?array
    {
        if ($this->strategy === self::DEFER) {
            return ['size' => $this->source->fileSize($sourcePath) ?? 0, 'checksum' => '', 'mime' => ''];
        }

        if (! $this->source->fileExists($sourcePath)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($targetPath));

        $absoluteSource = $this->source->filePath($sourcePath);
        $absoluteTarget = $disk->path($targetPath);

        $linked = $this->strategy === self::HARDLINK
            && $absoluteSource !== null
            && @link($absoluteSource, $absoluteTarget);

        if ($this->strategy === self::MOVE && $absoluteSource !== null && ! $linked) {
            $linked = @rename($absoluteSource, $absoluteTarget);
        }

        if (! $linked && ! $this->stream($sourcePath, $targetPath)) {
            return null;
        }

        return [
            'size' => (int) $disk->size($targetPath),
            'checksum' => $this->checksums ? $this->checksum($absoluteTarget, $targetPath) : '',
            'mime' => (string) ($disk->mimeType($targetPath) ?: 'application/octet-stream'),
        ];
    }

    private function stream(string $sourcePath, string $targetPath): bool
    {
        $handle = $this->source->openFile($sourcePath);

        if ($handle === null) {
            return false;
        }

        try {
            Storage::disk(self::DISK)->writeStream($targetPath, $handle);
        } catch (RuntimeException) {
            return false;
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Streamed rather than `hash_file()` so it behaves the same when the
     * files disk is not local storage.
     */
    private function checksum(?string $absoluteTarget, string $targetPath): string
    {
        $stream = Storage::disk(self::DISK)->readStream($targetPath);

        if ($stream === null) {
            return '';
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
