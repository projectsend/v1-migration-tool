<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1FilePath;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;
use ProjectSend\V1Migration\Transform\SlugReserver;

/**
 * Files: the row and its bytes, together.
 *
 * Done in one pass on purpose. Splitting them would mean writing rows
 * with an empty `checksum` (the column is NOT NULL) and filling it later
 * — so the database would briefly claim a checksum that is not one. The
 * only mode that does that is `--files=defer`, where it is the explicit
 * point.
 *
 * ### v1 has three "names" and only one of them is a name
 *
 *   `url`           the filename **on disk**
 *   `original_url`  the filename as uploaded
 *   `filename`      the **display title** shown in the interface
 *
 * The last one is the misleading one, and getting it wrong shows an
 * install full of `1712849302-a1b2…-report.pdf` where the titles used to
 * be.
 *
 * ### Encoding depends on whether the file was ever edited
 *
 * `Files::setDefaults()` stores the title raw on upload; `Files::save()`
 * runs it through `encode_html()` on edit. So a real install holds a
 * mixture, and decoding has to be safe for both — which it is, since
 * decoding a string with no entities in it returns the string.
 *
 * ### Expiry
 *
 * v1's `expiry_date` is `TIMESTAMP NOT NULL` with a default in the far
 * future, so it always holds a date whether or not the file expires. The
 * `expires` flag is the only thing that says whether that date means
 * anything, and v2 expresses "never" as NULL — reading the date without
 * checking the flag would give every file in the install an expiry.
 */
final class FilesPhase extends TablePhase
{
    private ?SlugReserver $slugs = null;

    private ?FileTransfer $transfer = null;

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return 'Files';
    }

    protected function table(): string
    {
        return V1Tables::FILES;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);
        $context->idMap->preload(MigrationIdMap::ENTITY_FOLDER);

        $slugs = $this->slugs ??= SlugReserver::seededFrom(HostTables::FILES, 'file');
        $transfer = $this->transfer ??= new FileTransfer(
            $context->source,
            (string) $context->option('files', FileTransfer::COPY),
            (bool) $context->option('checksums', true),
        );

        $dated = $context->source->manifest()->uploadsOrganizedByDate;
        $seen = $context->idMap->alreadySeen(
            MigrationIdMap::ENTITY_FILE,
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
        );

        $now = now();

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];

            // A worker can die between writing rows and recording where
            // it got to; the chunk is then repeated. Bytes have already
            // moved for these, so redoing them would duplicate files.
            if (isset($seen[$sourceId])) {
                continue;
            }

            if ((int) ($row['encrypted'] ?? 0) === 1) {
                $context->idMap->skip(MigrationIdMap::ENTITY_FILE, $sourceId, 'encrypted at rest');
                $context->skipped($this->key(), 'encrypted at rest in v1; v2 has no equivalent');

                continue;
            }

            if (($row['storage_type'] ?? 'local') !== 'local') {
                $context->idMap->skip(MigrationIdMap::ENTITY_FILE, $sourceId, 'stored externally');
                $context->skipped($this->key(), 'stored on S3/GCS/Azure rather than on disk');

                continue;
            }

            $sourcePath = V1FilePath::for($row, $dated);
            $uploadedAt = isset($row['timestamp']) ? (string) $row['timestamp'] : null;
            $targetPath = $transfer->targetPath($sourcePath, $uploadedAt);
            $result = $transfer->transfer($sourcePath, $targetPath);

            if ($result === null) {
                $context->idMap->skip(MigrationIdMap::ENTITY_FILE, $sourceId, 'no bytes on disk');
                $context->skipped($this->key(), 'the file row exists but its bytes are gone');

                continue;
            }

            $title = LegacyText::line((string) ($row['filename'] ?? '')) ?: (string) $row['url'];

            $id = (int) DB::table(HostTables::FILES)->insertGetId([
                'uploaded_by' => $context->idMap->lookup(MigrationIdMap::ENTITY_USER, $this->intOrNull($row['user_id'] ?? null)),
                'folder_id' => $context->idMap->lookup(MigrationIdMap::ENTITY_FOLDER, $this->intOrNull($row['folder_id'] ?? null)),
                'name' => $title,
                'slug' => $slugs->reserve($title),
                'description' => LegacyText::decode($row['description'] ?? null),
                'original_name' => (string) ($row['original_url'] ?? $row['url']),
                'path' => $targetPath,
                'disk' => FileTransfer::DISK,
                'mime_type' => $result['mime'] !== '' ? $result['mime'] : 'application/octet-stream',
                'size' => $result['size'] > 0 ? $result['size'] : (int) ($row['size'] ?? 0),
                'checksum' => $result['checksum'],
                'public' => (int) ($row['public_allow'] ?? 0) === 1,
                'expires_at' => (int) ($row['expires'] ?? 0) === 1 ? ($row['expiry_date'] ?? null) : null,
                'created_at' => $row['timestamp'] ?? $now,
                'updated_at' => $now,
            ]);

            $context->idMap->record(MigrationIdMap::ENTITY_FILE, $sourceId, $id);
            $context->count($this->key(), 'imported');

            if ((int) ($row['download_limit_enabled'] ?? 0) === 1) {
                $context->count($this->key(), 'download_limits_not_carried');
            }
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
