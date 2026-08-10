<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A live ProjectSend v1 database plus its upload directory, read in
 * place.
 *
 * The fast path, and the only one that can hardlink instead of copying —
 * which is the difference between seconds and hours on a 400 GB install.
 * It requires v1 and v2 on the same machine (or at least the same
 * filesystem and network), which is exactly the self-hosted upgrade
 * case.
 *
 * Nothing here writes to v1. Not a flag, not a marker row, not a lock:
 * the source install must still be usable and rollback-able after a
 * failed import, and the only way to guarantee that is to never touch it.
 *
 * Two connection settings are load-bearing, not defaults:
 *
 *   timezone '+00:00' — MySQL converts TIMESTAMP columns through the
 *   *session* timezone on the way out. v1 stores a single global display
 *   timezone in tbl_options and the v1 process reads its dates through
 *   it; if this connection used the server default instead, every
 *   imported date would shift by that offset. Pinning both sides to UTC
 *   makes the round trip identity.
 *
 *   charset utf8mb4 — every v1 table is utf8mb3 and v1 itself connects
 *   with `SET NAMES utf8`. Reading as utf8mb4 is safe (MySQL widens) and
 *   is the only thing that survives a customer who ran `ALTER … CONVERT
 *   TO utf8mb4` by hand at some point, which does happen.
 */
final class LegacyDatabaseSource implements MigrationSource
{
    public const CONNECTION = 'projectsend_v1';

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, string|null>|null */
    private ?array $options = null;

    public function __construct(
        private readonly string $prefix,
        private readonly string $filesRoot,
        private readonly ?string $version = null,
        private readonly bool $hasEncryptionKey = false,
    ) {}

    /**
     * Register the v1 connection for this process.
     *
     * Runtime config is safe here specifically because ProjectSend
     * forbids `config:cache` (see the host's INSTALL.md) — a cached
     * config would not see this.
     *
     * @param  array{host: string, port?: int|string, database: string, username: string, password: string}  $credentials
     */
    public static function connect(array $credentials): void
    {
        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'mysql',
            'host' => $credentials['host'],
            'port' => (string) ($credentials['port'] ?? 3306),
            'database' => $credentials['database'],
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'timezone' => '+00:00',
        ]);

        DB::purge(self::CONNECTION);
    }

    public function manifest(): SourceManifest
    {
        $options = $this->options();

        return new SourceManifest(
            kind: 'direct',
            version: $this->version,
            databaseVersion: isset($options['database_version'])
                ? (int) $options['database_version']
                : null,
            counts: $this->countAll(),
            uploadsOrganizedByDate: ($options['uploads_organize_folders_by_date'] ?? '0') === '1',
            hasEncryptionKey: $this->hasEncryptionKey,
            filesIncluded: is_dir($this->filesRoot),
            timezone: $options['timezone'] ?? null,
        );
    }

    public function count(string $table): int
    {
        return $this->counts[$table] ??= (int) $this->query($table)->count();
    }

    public function rows(string $table, int $afterId = 0, int $limit = 1000): array
    {
        return array_values($this->query($table)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
    }

    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];

        // Ordered by id so that when a name appears twice — v1 has no
        // unique key on it, and settings-form posts have been observed
        // writing junk rows — the most recently written one wins.
        foreach ($this->query('options')->orderBy('id')->get() as $row) {
            $options[(string) $row->name] = $row->value === null ? null : (string) $row->value;
        }

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

        // v1 stores the on-disk name in a text column that has been
        // through generate_safe_filename(), but the directory part is
        // derived by this tool from disk_folder_year/month. Refuse
        // traversal outright rather than trusting either.
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        return rtrim($this->filesRoot, '/').'/'.$relativePath;
    }

    /**
     * @return array<string, int>
     */
    private function countAll(): array
    {
        $counts = [];

        foreach (V1Tables::all() as $table) {
            $counts[$table] = $this->count($table);
        }

        return $counts;
    }

    private function query(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::connection(self::CONNECTION)->table($this->prefix.$table);
    }
}
