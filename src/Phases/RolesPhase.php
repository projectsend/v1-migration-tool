<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostBridge;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;

/**
 * v1's roles onto v2's.
 *
 * Three cases, and they are not symmetric:
 *
 * **Roles v2 already has** (Client, Account Manager, System
 * Administrator) are matched by name and reused. They exist on a fresh
 * install because the host seeds them at boot; creating a second
 * "Client" would fail the unique constraint and, worse, split accounts
 * across two roles that look identical.
 *
 * **Uploader** exists in the host's SystemRole enum but is never seeded
 * — its docblock says it is kept "only so the v1 → v2 migration tool can
 * recreate it". So the host is asked to materialise it, which gets the
 * host's own name and default permissions for it instead of this
 * package's guess.
 *
 * **Custom roles** are created outright, with whichever of their
 * permissions v2 still recognises.
 *
 * Matching is by name rather than id on purpose. v1's system role ids
 * are stable on a fresh install, but an install that has been through
 * ten years of upgrades is not a fresh install, and v1 itself decides
 * who is a client by the role's *name* being literally "Client"
 * (`Roles::isClientRole()`), not by its id.
 */
final class RolesPhase extends TablePhase
{
    /**
     * v1 role name => the host SystemRole case to reuse.
     */
    private const SYSTEM_ROLES = [
        'Client' => 'Client',
        'Account Manager' => 'Account Manager',
        'System Administrator' => 'System Administrator',
        'Uploader' => 'Uploader',
    ];

    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return 'Roles';
    }

    protected function table(): string
    {
        return V1Tables::ROLES;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $now = now();

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];
            $name = LegacyText::line((string) $row['name']) ?? '';

            if ($name === '') {
                $context->idMap->skip(MigrationIdMap::ENTITY_ROLE, $sourceId, 'role has no name');
                $context->skipped($this->key(), 'role has no name');

                continue;
            }

            $existing = HostBridge::roleIdByName($name);

            if ($existing !== null) {
                $context->idMap->record(MigrationIdMap::ENTITY_ROLE, $sourceId, $existing, 'matched a role this install already had');
                $context->count($this->key(), 'matched_existing');

                continue;
            }

            if (isset(self::SYSTEM_ROLES[$name])) {
                $materialized = HostBridge::materializeSystemRole(self::SYSTEM_ROLES[$name]);

                if ($materialized !== null) {
                    $context->idMap->record(MigrationIdMap::ENTITY_ROLE, $sourceId, $materialized);
                    $context->count($this->key(), 'materialized_builtin');

                    continue;
                }
            }

            $id = (int) DB::table(HostTables::ROLES)->insertGetId([
                'name' => $this->uniqueName($name),
                'is_system' => false,
                'is_administrator' => false,

                // v1 has no notion of a role scoped to the clients an
                // account manages — that is v2's Client Manager idea —
                // so an imported custom role is never client-scoped.
                'client_scoped' => false,
                'created_at' => $context->clock->toUtc($row['created_date'] ?? null) ?? $now,
                'updated_at' => $now,
            ]);

            $context->idMap->record(MigrationIdMap::ENTITY_ROLE, $sourceId, $id);
            $context->count($this->key(), 'created_custom');
        }
    }

    /**
     * v2's roles.name is unique and v1's is not. A second "Editor" gets
     * a suffix rather than failing the run — losing a role would move
     * every account holding it onto whichever one won.
     */
    private function uniqueName(string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (HostBridge::roleIdByName($candidate) !== null) {
            $candidate = $name.' ('.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }
}
