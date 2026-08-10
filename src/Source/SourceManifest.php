<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

/**
 * What the source says about itself, before anything is read from it.
 *
 * Preflight shows this to the operator so they can confirm they pointed
 * at the install they meant — "5,000 files, 24 months of history, dated
 * upload folders" is recognisable in a way a connection string is not.
 */
final class SourceManifest
{
    /**
     * @param  string|null  $version  v1's CURRENT_VERSION, e.g. 'r2098'
     * @param  int|null  $databaseVersion  tbl_options.database_version
     * @param  array<string, int>  $counts  v1 table name => row count
     * @param  bool  $uploadsOrganizedByDate  v1's
     *         uploads_organize_folders_by_date. Decides whether files sit
     *         flat in upload/files or under YYYY/MM, and both layouts
     *         exist in the wild
     * @param  bool  $hasEncryptionKey  whether the source install has an
     *         ENCRYPTION_MASTER_KEY. Reported, never carried — the key
     *         itself must not travel in a bundle
     * @param  bool  $filesIncluded  whether file bytes are reachable from
     *         this source at all, or only their inventory
     * @param  list<string>  $warnings  produced by the exporter on the v1
     *         side, where things this side cannot see are still visible
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $version = null,
        public readonly ?int $databaseVersion = null,
        public readonly array $counts = [],
        public readonly bool $uploadsOrganizedByDate = false,
        public readonly bool $hasEncryptionKey = false,
        public readonly bool $filesIncluded = true,
        public readonly ?string $timezone = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'version' => $this->version,
            'database_version' => $this->databaseVersion,
            'counts' => $this->counts,
            'uploads_organized_by_date' => $this->uploadsOrganizedByDate,
            'has_encryption_key' => $this->hasEncryptionKey,
            'files_included' => $this->filesIncluded,
            'timezone' => $this->timezone,
            'warnings' => $this->warnings,
        ];
    }
}
