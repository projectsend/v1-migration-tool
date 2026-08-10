<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Who each file was shared with.
 *
 * v1's `files_relations` is one row per assignment, and the row names
 * either a client or a group — never both, never neither. v2 keeps the
 * same idea in a polymorphic table, so the translation is direct: the
 * `assignable_type` is a fully-qualified class name, because the host
 * registers no morph map (verified: nothing calls `enforceMorphMap`).
 *
 * Two v1 columns are deliberately not carried:
 *
 * `hidden` — v1 could assign a file to someone and hide it from them.
 * v2 has no such state, and the only two ways to represent it would be
 * to create the assignment (showing people files that were hidden from
 * them) or to silently drop it. Dropping is the safe direction, and each
 * one is counted.
 *
 * `download_count` — legacy and stale in v1 too; it is 0 on every row of
 * every install. v2 counts downloads from the activity log, which the
 * history phase fills in.
 */
final class FileAssignmentsPhase extends TablePhase
{
    public function key(): string
    {
        return 'file_assignments';
    }

    public function label(): string
    {
        return 'File sharing';
    }

    protected function table(): string
    {
        return V1Tables::FILES_RELATIONS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);
        $context->idMap->preload(MigrationIdMap::ENTITY_GROUP);

        $fileIds = $context->idMap->lookupMany(
            MigrationIdMap::ENTITY_FILE,
            array_map(static fn (array $row): int => (int) $row['file_id'], $rows),
        );

        $now = now();
        $insert = [];

        foreach ($rows as $row) {
            $fileId = $fileIds[(int) $row['file_id']] ?? null;

            if ($fileId === null) {
                $context->skipped($this->key(), 'the file was not imported');

                continue;
            }

            if ((int) ($row['hidden'] ?? 0) === 1) {
                $context->skipped($this->key(), 'assignment was hidden in v1; v2 has no hidden state');

                continue;
            }

            $clientId = $this->intOrNull($row['client_id'] ?? null);
            $groupId = $this->intOrNull($row['group_id'] ?? null);

            if ($clientId !== null) {
                $target = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, $clientId);
                $type = HostTables::MORPH_USER;
            } elseif ($groupId !== null) {
                $target = $context->idMap->lookup(MigrationIdMap::ENTITY_GROUP, $groupId);
                $type = HostTables::MORPH_GROUP;
            } else {
                $context->skipped($this->key(), 'assignment names neither a client nor a group');

                continue;
            }

            if ($target === null) {
                $context->skipped($this->key(), 'the client or group was not imported');

                continue;
            }

            $insert[] = [
                'file_id' => $fileId,
                'assignable_type' => $type,
                'assignable_id' => $target,
                'created_at' => $row['timestamp'] ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::FILE_ASSIGNMENTS)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
