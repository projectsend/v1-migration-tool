<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

/**
 * A ProjectSend v1 install, however it is reachable.
 *
 * Two implementations, one importer. Direct mode reads the live v1
 * database and its upload directory; bundle mode reads NDJSON and an
 * optional file payload produced by bin/projectsend-v1-export.php on a
 * machine this one cannot reach. Everything downstream of this interface
 * is written once.
 *
 * Rows are always ordered by `id` and fetched by keyset (`afterId`),
 * never by OFFSET. Every v1 table has an auto-increment primary key, so
 * this works uniformly — and it is the only pagination that stays
 * correct and cheap five million rows into an activity log.
 *
 * Table names here are v1 names *without* the prefix: 'users', 'files',
 * 'files_relations'. Each implementation knows how to reach its own
 * storage from that.
 */
interface MigrationSource
{
    public function manifest(): SourceManifest;

    public function count(string $table): int;

    /**
     * One page of rows, ordered by id, with id > $afterId.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, int $afterId = 0, int $limit = 1000): array;

    /**
     * v1's whole options table as name => value.
     *
     * Returned as one array because the settings phase needs to see all
     * of it at once, and because v1 has **no unique key on `name`** —
     * duplicate rows occur in the wild, and this is where "last row
     * wins" gets decided once instead of at every call site.
     *
     * @return array<string, string|null>
     */
    public function options(): array;

    /**
     * Whether a file exists in v1's upload directory, given a path
     * relative to it (e.g. '2026/03/1712-ab…-report.pdf').
     */
    public function fileExists(string $relativePath): bool;

    public function fileSize(string $relativePath): ?int;

    /**
     * A read stream for a v1 file, or null when the bytes are not there.
     * The caller closes it.
     *
     * @return resource|null
     */
    public function openFile(string $relativePath);

    /**
     * The absolute path of a v1 file when this source has one, or null
     * when it does not (a bundle that shipped an inventory only).
     * Hardlinking needs a real path; streaming does not.
     */
    public function filePath(string $relativePath): ?string;
}
