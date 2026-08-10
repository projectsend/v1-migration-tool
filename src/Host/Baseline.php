<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Host;

use Illuminate\Support\Facades\DB;

/**
 * The highest id in every host table before a run starts.
 *
 * This is what makes `:reset` exact rather than approximate. The
 * alternative — deleting by the id map — cannot work: activity log rows,
 * pivot rows and assignment rows are not mapped individually, and
 * mapping five million of them would cost more than the import. A
 * baseline plus "delete everything above it" covers all of them in one
 * statement per table, and cannot touch anything that was there first.
 *
 * It relies on fresh-install-only, and reinforces it: with nothing else
 * writing to the install during a migration, "above the baseline" and
 * "created by this run" are the same set.
 */
final class Baseline
{
    /**
     * @return array<string, int>
     */
    public static function capture(): array
    {
        $baseline = [];

        foreach (array_keys(HostTables::writes()) as $table) {
            $baseline[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }

        return $baseline;
    }
}
