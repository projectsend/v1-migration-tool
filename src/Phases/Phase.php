<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use ProjectSend\V1Migration\MigrationContext;

/**
 * One step of an import, run in chunks so it can be interrupted.
 *
 * Phases never run to completion in one call. The production container
 * recycles its queue worker every hour
 * (`queue:work --max-time=3600`), and a 400 GB import outlives that, so
 * a phase that could only be run atomically could never finish. Instead
 * the runner asks for one chunk at a time and persists the cursor after
 * each, which also means a chunk is the unit of work that either
 * committed or did not.
 *
 * The cursor is a v1 primary key: every v1 table has an auto-increment
 * `id`, so "everything up to 41,000" is a complete description of
 * progress that survives a process dying.
 */
interface Phase
{
    /**
     * Stable identifier, stored in v1_migration_runs.phase. Renaming one
     * strands any run that was paused inside it.
     */
    public function key(): string;

    /**
     * What the progress UI shows while this is running.
     */
    public function label(): string;

    /**
     * How many source rows this phase will consider, for the progress
     * bar. Counted, not estimated — and read from the source's own
     * count, never by scanning.
     */
    public function total(MigrationContext $context): int;

    /**
     * Process one chunk starting after $cursor.
     *
     * Returns the new cursor, or **null when the phase is finished**.
     * Must be safe to call again with the same cursor: a worker can die
     * between doing the work and recording where it got to, and the id
     * map is what makes the repeat harmless.
     */
    public function chunk(MigrationContext $context, int $cursor): ?int;
}
