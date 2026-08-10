<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Models\MigrationIdMap;

/**
 * Translates v1 primary keys into the v2 rows they became.
 *
 * Two access patterns, because the entities differ by orders of
 * magnitude. Roles, users, groups, categories and folders number in the
 * hundreds or low thousands, so they are preloaded once and answered
 * from memory. Files can number 200,000, and the phases that need them
 * (assignments, categories, downloads, the activity log) walk the source
 * in chunks anyway — so those resolve a chunk's worth of ids at a time
 * with one query, and nothing large is ever held.
 *
 * The tempting third option — a SQL join from the source rows to this
 * table — is not available: the source is either a different database
 * connection or a directory of NDJSON files, and neither can be joined
 * against a host table.
 *
 * Writes are buffered and flushed in bulk. Every phase must flush inside
 * the same transaction that wrote the rows being mapped, or a recycled
 * worker resumes with rows that exist and are unmapped, which reads as
 * "not yet imported" and duplicates them.
 */
final class IdMap
{
    /** @var array<string, array<int, int|null>> */
    private array $preloaded = [];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function __construct(private readonly int $runId) {}

    /**
     * Pull an entire entity's mapping into memory. Only for entities
     * whose row count is bounded by how many a human created by hand.
     */
    public function preload(string $entity): void
    {
        $this->preloaded[$entity] = MigrationIdMap::query()
            ->where('run_id', $this->runId)
            ->where('entity', $entity)
            ->pluck('target_id', 'source_id')
            ->map(static fn ($id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    public function lookup(string $entity, ?int $sourceId): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        if (isset($this->preloaded[$entity])) {
            return $this->preloaded[$entity][$sourceId] ?? null;
        }

        $target = MigrationIdMap::query()
            ->where('run_id', $this->runId)
            ->where('entity', $entity)
            ->where('source_id', $sourceId)
            ->value('target_id');

        return $target === null ? null : (int) $target;
    }

    /**
     * @param  list<int>  $sourceIds
     * @return array<int, int> source id => target id, omitting anything
     *                         unmapped or deliberately skipped
     */
    public function lookupMany(string $entity, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(
            $sourceIds,
            static fn (?int $id): bool => $id !== null && $id > 0,
        )));

        if ($sourceIds === []) {
            return [];
        }

        if (isset($this->preloaded[$entity])) {
            $map = [];
            foreach ($sourceIds as $id) {
                $target = $this->preloaded[$entity][$id] ?? null;
                if ($target !== null) {
                    $map[$id] = $target;
                }
            }

            return $map;
        }

        return MigrationIdMap::query()
            ->where('run_id', $this->runId)
            ->where('entity', $entity)
            ->whereIn('source_id', $sourceIds)
            ->whereNotNull('target_id')
            ->pluck('target_id', 'source_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function record(string $entity, int $sourceId, int $targetId): void
    {
        $this->buffer($entity, $sourceId, $targetId, MigrationIdMap::STATUS_IMPORTED, null);
    }

    /**
     * A source row that was seen and deliberately not imported. It is
     * mapped just like an imported one — with a null target — so a
     * resumed run knows it has already been dealt with, and so the
     * report can list exactly what was left behind and why.
     */
    public function skip(string $entity, int $sourceId, string $note): void
    {
        $this->buffer($entity, $sourceId, null, MigrationIdMap::STATUS_SKIPPED, $note);
    }

    /**
     * Source ids from this set that have already been dealt with — the
     * guard that makes re-running a chunk safe after a worker restart.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, true>
     */
    public function alreadySeen(string $entity, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $seen = MigrationIdMap::query()
            ->where('run_id', $this->runId)
            ->where('entity', $entity)
            ->whereIn('source_id', $sourceIds)
            ->pluck('source_id');

        $map = [];
        foreach ($seen as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        foreach (array_chunk($this->pending, 500) as $chunk) {
            DB::table('v1_migration_id_map')->insert($chunk);
        }

        foreach ($this->pending as $row) {
            $entity = (string) $row['entity'];
            if (isset($this->preloaded[$entity])) {
                $this->preloaded[$entity][(int) $row['source_id']] = $row['target_id'] === null
                    ? null
                    : (int) $row['target_id'];
            }
        }

        $this->pending = [];
    }

    private function buffer(string $entity, int $sourceId, ?int $targetId, string $status, ?string $note): void
    {
        $this->pending[] = [
            'run_id' => $this->runId,
            'entity' => $entity,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'status' => $status,
            'note' => $note,
        ];
    }
}
