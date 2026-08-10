<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

use RuntimeException;

/**
 * A v1 install exported to a portable directory by
 * bin/projectsend-v1-export.php.
 *
 * The path for anything the v2 install cannot reach directly: a hosted
 * ProjectSend onboarding a customer whose v1 sits on their own network,
 * or simply two different servers. The customer runs one dependency-free
 * PHP file on the v1 box and uploads what it produces.
 *
 * Layout:
 *
 *   manifest.json            counts, version, warnings, options snapshot
 *   tables/<name>.ndjson.gz  one JSON object per line, ordered by id
 *   files.ndjson             file inventory (path, size, present)
 *   files/                   optional payload, mirroring v1's upload/files
 *
 * ### Why reads are forward-only
 *
 * A gzipped NDJSON stream cannot seek, so `rows($table, $afterId)` is
 * served by keeping the handle open and continuing from where the last
 * call stopped. Asking for an $afterId behind the cursor reopens the
 * file and scans forward — correct, but O(n) — which is why the import
 * job processes many chunks inside one invocation rather than one chunk
 * per job. At five million activity rows the difference between "scan
 * forward once per worker lifetime" and "scan forward once per thousand
 * rows" is the difference between minutes and never finishing.
 */
final class BundleSource implements MigrationSource
{
    /** @var array<string, BundleTableReader> */
    private array $readers = [];

    /** @var array<string, mixed>|null */
    private ?array $manifestData = null;

    /** @var array<string, string|null>|null */
    private ?array $options = null;

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path.'/manifest.json')) {
            throw new RuntimeException(
                "No manifest.json in {$this->path} — this is not a ProjectSend v1 export bundle.",
            );
        }
    }

    public function manifest(): SourceManifest
    {
        $data = $this->manifestData();

        return new SourceManifest(
            kind: 'bundle',
            version: isset($data['version']) ? (string) $data['version'] : null,
            databaseVersion: isset($data['database_version']) ? (int) $data['database_version'] : null,
            counts: array_map(intval(...), (array) ($data['counts'] ?? [])),
            uploadsOrganizedByDate: (bool) ($data['uploads_organized_by_date'] ?? false),
            hasEncryptionKey: (bool) ($data['has_encryption_key'] ?? false),
            filesIncluded: is_dir($this->path.'/files'),
            timezone: isset($data['timezone']) ? (string) $data['timezone'] : null,
            warnings: array_values(array_map(strval(...), (array) ($data['warnings'] ?? []))),
        );
    }

    public function count(string $table): int
    {
        $counts = (array) ($this->manifestData()['counts'] ?? []);

        return (int) ($counts[$table] ?? 0);
    }

    public function rows(string $table, int $afterId = 0, int $limit = 1000): array
    {
        $reader = $this->reader($table, $afterId);

        if ($reader === null) {
            return [];
        }

        $rows = [];

        // A row read past the end of the previous chunk while scanning
        // forward. Held rather than re-read, because gzip cannot seek
        // back a line without restarting the stream.
        $pending = $reader->takePending($afterId);

        if ($pending !== null) {
            $rows[] = $pending;
            $reader->lastId = (int) $pending['id'];
        }

        while (count($rows) < $limit) {
            $row = $reader->read();

            if ($row === null) {
                break;
            }

            $id = (int) ($row['id'] ?? 0);

            // Tolerated rather than assumed: a bundle produced by a
            // future exporter, or hand-edited, might not be perfectly
            // ordered. Skipping is cheaper than failing the run.
            if ($id <= $afterId) {
                continue;
            }

            $rows[] = $row;
            $reader->lastId = $id;
        }

        return $rows;
    }

    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];
        $afterId = 0;

        while (($rows = $this->rows(V1Tables::OPTIONS, $afterId, 500)) !== []) {
            foreach ($rows as $row) {
                $options[(string) $row['name']] = $row['value'] === null ? null : (string) $row['value'];
                $afterId = (int) $row['id'];
            }
        }

        // The options reader is now at EOF and this source may be asked
        // for options again by a later phase; drop it so a re-read
        // reopens rather than returning nothing.
        $this->closeReader(V1Tables::OPTIONS);

        return $this->options = $options;
    }

    public function fileExists(string $relativePath): bool
    {
        $path = $this->filePath($relativePath);

        return $path !== null && is_file($path);
    }

    public function fileSize(string $relativePath): ?int
    {
        $path = $this->filePath($relativePath);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }

    public function openFile(string $relativePath)
    {
        $path = $this->filePath($relativePath);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        return $handle === false ? null : $handle;
    }

    public function filePath(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        return $this->path.'/files/'.$relativePath;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestData(): array
    {
        if ($this->manifestData !== null) {
            return $this->manifestData;
        }

        $raw = file_get_contents($this->path.'/manifest.json');

        if ($raw === false) {
            throw new RuntimeException("Could not read {$this->path}/manifest.json.");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $this->manifestData = $data;
    }

    private function reader(string $table, int $afterId): ?BundleTableReader
    {
        $file = $this->path.'/tables/'.$table.'.ndjson.gz';

        if (! is_file($file)) {
            return null;
        }

        $reader = $this->readers[$table] ?? null;

        if ($reader !== null && $reader->lastId <= $afterId) {
            return $reader;
        }

        // Either nothing is open yet, or the caller has gone backwards —
        // a resumed run picking up mid-table. Reopen and scan forward.
        $reader = new BundleTableReader($file);
        $this->readers[$table] = $reader;

        if ($afterId > 0) {
            $reader->scanTo($afterId);
        }

        return $reader;
    }

    private function closeReader(string $table): void
    {
        unset($this->readers[$table]);
    }
}
