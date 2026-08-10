<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostBridge;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * What each imported role may do.
 *
 * v1's permission keys were carried into v2 **verbatim** — the host's
 * Permission enum says so in its own docblock — so this is a filter, not
 * a translation. Four keys were dropped along the way (`unblock_ip`,
 * `edit_self_account`, `test_email`, `change_template`), each by a host
 * migration that deleted its rows; writing them back would undo that.
 * The list of survivors is read from the host's enum rather than copied
 * here, because a copy would drift and drift here means silently
 * granting or withholding access.
 *
 * Rows for the administrator role are skipped deliberately: v2's System
 * Administrator holds every permission by construction in
 * `PermissionChecker`, with no pivot rows at all. Writing them would be
 * harmless but misleading — a later reader would think the set was
 * editable.
 *
 * v1's `granted` column is honoured. A row with `granted = 0` is v1
 * recording that a permission was explicitly taken away, which in v2's
 * model is simply the absence of the row.
 */
final class RolePermissionsPhase extends TablePhase
{
    /**
     * Keys v2 removed after the fork, listed only for the case where the
     * host cannot be asked (the package's own test suite). When the host
     * is present its enum is authoritative and this is not consulted.
     */
    private const REMOVED_IN_V2 = [
        'unblock_ip',
        'edit_self_account',
        'test_email',
        'change_template',
    ];

    public function key(): string
    {
        return 'role_permissions';
    }

    public function label(): string
    {
        return 'Role permissions';
    }

    protected function table(): string
    {
        return V1Tables::ROLE_PERMISSIONS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_ROLE);

        $known = HostBridge::permissionKeys();
        $administrators = $this->administratorRoleIds();
        $insert = [];

        foreach ($rows as $row) {
            $permission = trim((string) ($row['permission'] ?? ''));
            $roleId = $context->idMap->lookup(MigrationIdMap::ENTITY_ROLE, (int) $row['role_id']);

            if ($roleId === null) {
                $context->skipped($this->key(), 'role was not imported');

                continue;
            }

            if ($permission === '') {
                // Observed in the wild: a permissions row with an empty
                // key, left behind by one of v1's upgrade scripts.
                $context->skipped($this->key(), 'permission row has no key');

                continue;
            }

            if ((int) ($row['granted'] ?? 1) !== 1) {
                $context->skipped($this->key(), 'permission was explicitly not granted in v1');

                continue;
            }

            if ($known === null ? in_array($permission, self::REMOVED_IN_V2, true) : ! in_array($permission, $known, true)) {
                $context->skipped($this->key(), "v2 has no `{$permission}` permission");

                continue;
            }

            if (in_array($roleId, $administrators, true)) {
                $context->skipped($this->key(), 'administrator roles hold every permission implicitly');

                continue;
            }

            $insert[] = ['role_id' => $roleId, 'permission' => $permission];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::ROLE_PERMISSION)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'granted', count($insert));
    }

    /**
     * @return list<int>
     */
    private function administratorRoleIds(): array
    {
        return array_values(DB::table(HostTables::ROLES)
            ->where('is_administrator', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all());
    }
}
