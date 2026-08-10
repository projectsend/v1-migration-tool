<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Host;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The few places where reaching into the host is better than guessing.
 *
 * This package cannot `use` host classes — it is built and tested with
 * no host present. But at *runtime* the host is right there, and for a
 * handful of questions its own answer is the only correct one: which
 * permission keys still exist, what a built-in role is called and what
 * it may do. Hardcoding those here would mean a copy that drifts, and
 * drift in this particular copy means silently granting or dropping
 * permissions.
 *
 * So: string class names, `class_exists()` guards, and a documented
 * fallback for when the class is absent. The same trick the host's own
 * packages use in the other direction (see the host's
 * docs/extension-points-architecture.md, "Settled by precedent").
 *
 * Everything here must work when the answer is "the host is not there" —
 * that is what the package's own test suite looks like.
 */
final class HostBridge
{
    private const PERMISSION_ENUM = 'App\Modules\Identity\Permissions\Permission';

    private const SYSTEM_ROLE_ENUM = 'App\Modules\Identity\Permissions\SystemRole';

    private const ENSURE_SYSTEM_ROLES = 'App\Modules\Identity\Permissions\EnsureSystemRoles';

    /**
     * Permission keys v2 still recognises, or null when the host is not
     * available to ask.
     *
     * v1's keys were carried into v2 verbatim — the host's Permission
     * enum says so in its own docblock — so this is not a translation.
     * It is a filter for the handful v2 dropped: `unblock_ip`,
     * `edit_self_account`, `test_email` and `change_template` all had
     * migrations removing their rows, and writing them back would
     * undo that.
     *
     * @return list<string>|null
     */
    public static function permissionKeys(): ?array
    {
        if (! class_exists(self::PERMISSION_ENUM)) {
            return null;
        }

        $enum = self::PERMISSION_ENUM;
        /** @var list<\BackedEnum> $cases */
        $cases = $enum::cases();

        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    /**
     * Ensure one of the host's built-in roles exists, by its name, and
     * return its id.
     *
     * Used for v1's `Uploader`, which v2 knows about but deliberately
     * never seeds: its SystemRole enum marks it legacy and its docblock
     * says it exists "only so the v1 → v2 migration tool can recreate it
     * for imported installs". Asking the host to materialise it gets the
     * host's own idea of that role's name, flags and default
     * permissions, rather than this package's guess at them.
     */
    public static function materializeSystemRole(string $case): ?int
    {
        if (! class_exists(self::SYSTEM_ROLE_ENUM) || ! class_exists(self::ENSURE_SYSTEM_ROLES)) {
            return null;
        }

        try {
            $enum = self::SYSTEM_ROLE_ENUM;
            $role = $enum::tryFrom($case);

            if ($role === null) {
                return null;
            }

            $model = app(self::ENSURE_SYSTEM_ROLES)->materialize($role);

            return is_object($model) && isset($model->id) ? (int) $model->id : null;
        } catch (Throwable) {
            // A host that has the class but a different signature is a
            // host this tool does not know; fall back rather than fail
            // the run over a role name.
            return null;
        }
    }

    /**
     * The id of an existing v2 role with this name, if there is one.
     */
    public static function roleIdByName(string $name): ?int
    {
        $id = DB::table(HostTables::ROLES)->where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }
}
