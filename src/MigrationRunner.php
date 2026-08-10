<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\Phase;
use Throwable;

/**
 * Drives the phases, and knows when to stop and come back.
 *
 * The shape here is set by one deployment fact: the production image
 * runs `queue:work --max-time=3600`, so the worker executing an import
 * is replaced roughly every hour. A 400 GB migration takes longer than
 * that. So a run is not one long job — it is a job that works until its
 * budget is nearly spent, records exactly where it got to, and
 * re-dispatches itself.
 *
 * Two rules make that safe:
 *
 * **Each chunk commits on its own.** Never one transaction around a
 * phase, let alone a run. A transaction spanning 200,000 inserts would
 * hold locks for the whole import, blow past MySQL's undo capacity, and
 * roll back everything on any failure — which is the opposite of what a
 * resumable import wants.
 *
 * **The id map is written in the same transaction as the rows it maps.**
 * If those two ever come apart, a resumed run sees rows that exist and
 * are unmapped, treats them as not yet imported, and duplicates them.
 */
final class MigrationRunner
{
    /**
     * Stop this long before the worker's own limit. The gap has to cover
     * the slowest single chunk — copying five hundred large files — or
     * the worker is killed mid-chunk and that chunk is repeated.
     */
    private const DEFAULT_BUDGET_SECONDS = 3000;

    /**
     * @param  list<Phase>  $phases
     */
    public function __construct(private readonly array $phases) {}

    /**
     * Work until the phases are done or the budget runs out.
     *
     * Returns true when the whole run finished, false when it stopped
     * early and should be dispatched again.
     */
    public function run(MigrationContext $context, ?int $budgetSeconds = null): bool
    {
        $deadline = microtime(true) + ($budgetSeconds ?? self::DEFAULT_BUDGET_SECONDS);
        $run = $context->run;

        foreach ($this->remainingPhases($run) as $phase) {
            if ($run->phase !== $phase->key()) {
                $run->phase = $phase->key();
                $run->processed = 0;
                $run->total = $phase->total($context);
                $run->save();
            }

            $cursor = (int) ($run->options['cursor'] ?? 0);

            while (true) {
                try {
                    $next = DB::transaction(static fn (): ?int => $phase->chunk($context, $cursor));
                } catch (Throwable $e) {
                    $context->flushNotes();
                    $run->status = MigrationRun::STATUS_FAILED;
                    $run->error = sprintf('%s in phase "%s": %s', $e::class, $phase->key(), $e->getMessage());
                    $run->finished_at = now();
                    $run->save();

                    throw $e;
                }

                $context->flushNotes();

                if ($next === null) {
                    break;
                }

                $cursor = $next;
                $run->processed = $cursor;
                $run->options = ['cursor' => $cursor] + $run->options;
                $run->save();

                if (microtime(true) >= $deadline) {
                    return false;
                }
            }

            $run->options = array_diff_key($run->options, ['cursor' => null]);
            $run->save();
        }

        $run->status = MigrationRun::STATUS_COMPLETED;
        $run->phase = null;
        $run->finished_at = now();
        $run->save();

        return true;
    }

    /**
     * The phases still to do, starting with whichever one the run was
     * interrupted inside.
     *
     * @return list<Phase>
     */
    private function remainingPhases(MigrationRun $run): array
    {
        if ($run->phase === null) {
            return $this->phases;
        }

        foreach ($this->phases as $index => $phase) {
            if ($phase->key() === $run->phase) {
                return array_slice($this->phases, $index);
            }
        }

        // The run was paused inside a phase this build no longer has —
        // the tool was upgraded mid-migration. Starting over from the
        // beginning is safe (the id map skips what exists) and is the
        // only correct answer, since the missing phase's work cannot be
        // assumed done.
        return $this->phases;
    }
}
