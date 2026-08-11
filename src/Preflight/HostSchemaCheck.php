<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

use Illuminate\Support\Facades\Schema;
use ProjectSend\V1Migration\Host\HostTables;

/**
 * Proves the host is the schema this package thinks it is, before a
 * single row is written.
 *
 * This exists because the contract in HostTables is not enforced by
 * anything the compiler or Composer can see: the package writes into
 * another application's tables by name, and that application ships its
 * own releases. Without this check a renamed column surfaces as a
 * database error partway through the files phase, with a half-imported
 * install and no clean way back — the failure mode that made
 * fresh-install-only the rule in the first place.
 *
 * Missing tables and columns are BLOCKERs, never warnings. There is no
 * "import what fits" mode: a users table without `storage_quota_mb` is
 * a host this version of the tool does not know how to write to, and
 * guessing which of the two is out of date is not the tool's job.
 *
 * The single exception is a table on HostTables::optional(), which exists
 * only because the host grew a feature that is not part of carrying an
 * installation across. Its absence is a NOTE and its phase skips — see
 * that method for why the general rule would be disproportionate there.
 * A missing *column* is still a blocker even on an optional table: a
 * table that exists with the wrong shape is a real disagreement, not an
 * absent feature.
 */
final class HostSchemaCheck
{
    /**
     * @return list<Finding>
     */
    public function run(?string $connection = null): array
    {
        $schema = Schema::connection($connection);

        $findings = [];
        $optional = HostTables::optional();

        foreach (HostTables::writes() as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $findings[] = in_array($table, $optional, true)
                    ? Finding::note(
                        'host.optional_table_missing',
                        "This ProjectSend is older than the `{$table}` feature, so that part will be skipped. Everything else imports normally.",
                        ['table' => $table],
                    )
                    : Finding::blocker(
                        'host.table_missing',
                        "The host has no `{$table}` table. This install is not a ProjectSend v2 database, or is older than this tool supports.",
                        ['table' => $table],
                    );

                continue;
            }

            $missing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => ! $schema->hasColumn($table, $column),
            ));

            if ($missing !== []) {
                $findings[] = Finding::blocker(
                    'host.columns_missing',
                    sprintf(
                        'The host `%s` table is missing %s: %s. Update ProjectSend, or use a version of this tool that matches it.',
                        $table,
                        count($missing) === 1 ? 'a column this tool writes' : 'columns this tool writes',
                        implode(', ', $missing),
                    ),
                    ['table' => $table, 'columns' => $missing],
                );
            }
        }

        return $findings;
    }
}
