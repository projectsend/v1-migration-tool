<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Which clients a restricted staff account may upload to.
 *
 * v1 calls this `user_limit_upload_to`; v2 calls it a staff→client
 * assignment and uses it for more than uploads. The rows mean the same
 * thing — this member of staff deals with these clients and not the rest
 * — so they carry across directly.
 *
 * v2 pairs this with a `client_scoped` role flag that v1 has no
 * equivalent for; imported roles are never client-scoped (see
 * RolesPhase), so these assignments exist without yet narrowing anything
 * until an administrator moves those accounts onto the Client Manager
 * role. That is deliberate: silently restricting an account's visibility
 * during a migration would look like data loss.
 */
final class StaffClientAssignmentsPhase extends TablePhase
{
    public function key(): string
    {
        return 'staff_client_assignments';
    }

    public function label(): string
    {
        return 'Staff client assignments';
    }

    protected function table(): string
    {
        return V1Tables::USER_LIMIT_UPLOAD_TO;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $now = now();
        $insert = [];

        foreach ($rows as $row) {
            $staffId = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) $row['user_id']);
            $clientId = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) $row['client_id']);

            if ($staffId === null || $clientId === null) {
                $context->skipped($this->key(), 'the staff account or client was not imported');

                continue;
            }

            $insert[] = [
                'staff_id' => $staffId,
                'client_id' => $clientId,
                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::STAFF_CLIENT_ASSIGNMENTS)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }
}
