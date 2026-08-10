<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests\Support;

/**
 * Writes an export bundle the way bin/projectsend-v1-export.php does.
 *
 * Tests build their fixtures through this rather than checking in
 * binary .gz files, so the format lives in exactly one place and a
 * change to it breaks loudly instead of leaving stale fixtures that
 * still pass.
 */
final class BundleBuilder
{
    private string $path;

    /** @var array<string, mixed> */
    private array $manifest = [
        'version' => 'r2098',
        'database_version' => 2026080702,
        'counts' => [],
        'uploads_organized_by_date' => false,
        'has_encryption_key' => false,
        'timezone' => 'UTC',
        'warnings' => [],
    ];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? sys_get_temp_dir().'/v1-bundle-'.bin2hex(random_bytes(6));

        @mkdir($this->path.'/tables', 0o777, true);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function table(string $name, array $rows): self
    {
        $handle = gzopen($this->path.'/tables/'.$name.'.ndjson.gz', 'wb');

        foreach ($rows as $row) {
            gzwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        }

        gzclose($handle);

        $this->manifest['counts'][$name] = count($rows);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function manifest(array $values): self
    {
        $this->manifest = array_replace($this->manifest, $values);

        return $this;
    }

    public function file(string $relativePath, string $contents): self
    {
        $full = $this->path.'/files/'.$relativePath;
        @mkdir(dirname($full), 0o777, true);
        file_put_contents($full, $contents);

        return $this;
    }

    public function write(): string
    {
        file_put_contents(
            $this->path.'/manifest.json',
            json_encode($this->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        return $this->path;
    }
}
