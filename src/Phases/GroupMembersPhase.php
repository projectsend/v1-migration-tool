<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Who is in which group.
 *
 * Worth stating because it decides what "migrated" means for file
 * sharing: v1 resolves a group assignment at *read* time, by looking at
 * who is in the group right now. v2 does the same. So membership has to
 * be imported as membership — flattening group assignments into
 * per-client rows during the migration would freeze the group as it was
 * on migration day, and every later change to it would stop affecting
 * files that were shared with it.
 */
final class GroupMembersPhase extends TablePhase
{
    public function key(): string
    {
        return 'group_members';
    }

    public function label(): string
    {
        return 'Group members';
    }

    protected function table(): string
    {
        return V1Tables::MEMBERS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_GROUP);
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $now = now();
        $insert = [];

        foreach ($rows as $row) {
            $groupId = $context->idMap->lookup(MigrationIdMap::ENTITY_GROUP, (int) $row['group_id']);
            $userId = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) $row['client_id']);

            if ($groupId === null || $userId === null) {
                $context->skipped($this->key(), 'group or client was not imported');

                continue;
            }

            $insert[] = [
                'group_id' => $groupId,
                'user_id' => $userId,
                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::GROUP_MEMBERS)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }
}
