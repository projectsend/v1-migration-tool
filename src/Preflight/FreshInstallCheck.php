<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;

/**
 * Refuses to import into a v2 install that already has content.
 *
 * The tool has no merge semantics: no per-entity conflict policy, no
 * rule for what happens when a v1 client and an existing v2 client share
 * an email, no way to tell an imported file from one someone uploaded
 * yesterday. Adding those is a much larger feature than importing into
 * an empty database, and every one of them is a way to damage a working
 * install. So the supported shape is a v2 that has been set up and not
 * yet used, and the recovery path for a bad run is `:reset` — which is
 * only safe *because* nothing else is in there.
 *
 * One staff user is expected and fine: an install cannot get past
 * EnsureSetupIsComplete without creating one, so demanding a literally
 * empty users table would make the tool impossible to reach.
 */
final class FreshInstallCheck
{
    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        $users = (int) DB::table(HostTables::USERS)->count();

        if ($users > 1) {
            $findings[] = Finding::blocker(
                'host.not_fresh_users',
                "This install already has {$users} accounts. The migration tool imports into a fresh v2 — one that has been set up and not yet used — because it has no way to merge v1 accounts with accounts that already exist here.",
                ['table' => HostTables::USERS, 'count' => $users],
            );
        }

        foreach (HostTables::mustBeEmpty() as $table) {
            $count = (int) DB::table($table)->count();

            if ($count > 0) {
                $findings[] = Finding::blocker(
                    'host.not_fresh',
                    "This install already has {$count} row(s) in `{$table}`. The migration tool imports into a fresh v2 only.",
                    ['table' => $table, 'count' => $count],
                );
            }
        }

        return $findings;
    }
}
