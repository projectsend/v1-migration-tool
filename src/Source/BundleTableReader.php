<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

use RuntimeException;

/**
 * A forward-only cursor over one gzipped NDJSON table in a bundle.
 *
 * gzip cannot seek backwards without re-inflating from byte zero, so
 * this never tries. Reading past the end of a chunk is normal — the row
 * that overshoots is held in `$pending` and handed to the next call
 * rather than pushed back into the stream.
 *
 * @internal to BundleSource
 */
final class BundleTableReader
{
    /** @var resource */
    private $handle;

    public int $lastId = 0;

    /** @var array<string, mixed>|null */
    public ?array $pending = null;

    public function __construct(private readonly string $file)
    {
        $handle = gzopen($this->file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$this->file}.");
        }

        $this->handle = $handle;
    }

    public function __destruct()
    {
        gzclose($this->handle);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        while (($line = gzgets($this->handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            return $row;
        }

        return null;
    }

    /**
     * Advance past every row up to and including $afterId, keeping the
     * first row beyond it. Only ever runs once per table per process,
     * when a recycled worker resumes mid-table.
     */
    public function scanTo(int $afterId): void
    {
        while (($row = $this->read()) !== null) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > $afterId) {
                $this->pending = $row;
                $this->lastId = $afterId;

                return;
            }

            $this->lastId = $id;
        }

        $this->lastId = $afterId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function takePending(int $afterId): ?array
    {
        $pending = $this->pending;
        $this->pending = null;

        if ($pending === null || (int) ($pending['id'] ?? 0) <= $afterId) {
            return null;
        }

        return $pending;
    }
}
