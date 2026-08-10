<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Pending and denied requests to join a group.
 *
 * Small but not skippable: a client who asked to join a group and is
 * still waiting would, if these were dropped, see the request vanish and
 * ask again — and an administrator's queue would empty itself on
 * migration day.
 *
 * v1 records the outcome as a `denied` flag; v2 as a status string plus
 * a `denied_at` timestamp that its cooldown setting measures from.
 * v1 has no such timestamp, so the request's own is used — which is the
 * only date v1 has, and puts the cooldown in roughly the right place
 * rather than starting everyone's afresh at migration.
 */
final class MembershipRequestsPhase extends TablePhase
{
    public function key(): string
    {
        return 'membership_requests';
    }

    public function label(): string
    {
        return 'Group membership requests';
    }

    protected function table(): string
    {
        return V1Tables::MEMBERS_REQUESTS;
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

            $denied = (int) ($row['denied'] ?? 0) === 1;

            $insert[] = [
                'group_id' => $groupId,
                'user_id' => $userId,
                'status' => $denied ? 'denied' : 'pending',
                'denied_at' => $denied ? ($context->clock->toUtc($row['timestamp'] ?? null) ?? $now) : null,
                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::MEMBERSHIP_REQUESTS)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }
}
