<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ProjectSend\V1Migration\Host\Baseline;
use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\MigrationRunner;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\PhaseRegistry;
use ProjectSend\V1Migration\Preflight\Preflight;
use ProjectSend\V1Migration\Source\SourceFactory;
use Throwable;

/**
 * Runs an import in the background, one budget at a time.
 *
 * Constructed with a bare id and re-loading the run in `handle()` — the
 * host's own convention for long jobs (see its `BuildZipDownloadJob`),
 * and necessary here for a second reason: this job re-dispatches itself,
 * and a serialised model would carry a stale snapshot of a row the
 * previous invocation has been writing to all along.
 *
 * ### Why it comes back rather than finishing
 *
 * The production image runs `queue:work --max-time=3600`, replacing the
 * worker every hour. An import of a large install takes longer than
 * that. So each invocation works until its budget is nearly spent,
 * leaves the cursor on the run row, and queues itself again. From the
 * outside it is one job; underneath it is however many the install
 * needs.
 *
 * ### Preflight runs here, not in the request
 *
 * Checking a v1 install means reading every account and every file row.
 * On the installs this tool exists for, that is not something to do
 * inside a web request — so the page queues the job, and the job's first
 * act is to check. That also makes the checking itself visible: the
 * status moves through `checking` before it reaches `running`.
 */
class RunMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One hour is the worker's lifetime; stopping well short of it
     * leaves room for the slowest single chunk to finish rather than
     * being killed halfway through.
     */
    private const BUDGET_SECONDS = 3000;

    public int $timeout = 3300;

    public int $tries = 1;

    public function __construct(private readonly int $runId) {}

    public function handle(SourceFactory $sources, Preflight $preflight): void
    {
        $run = MigrationRun::find($this->runId);

        if ($run === null || $run->isFinished()) {
            return;
        }

        try {
            $source = $sources->make($run);
        } catch (Throwable $e) {
            $this->fail($run, 'Could not read the source: '.$e->getMessage());

            return;
        }

        if ($run->status === MigrationRun::STATUS_PENDING) {
            $run->status = MigrationRun::STATUS_CHECKING;
            $run->save();

            $report = $preflight->run($source);

            if ($report->isBlocked()) {
                $run->status = MigrationRun::STATUS_BLOCKED;
                $run->report = ['preflight' => $report->toArray()];
                $run->save();

                return;
            }

            if ($report->needsAcknowledgement() && ! ($run->options['accept_skips'] ?? false)) {
                $run->status = MigrationRun::STATUS_NEEDS_ACKNOWLEDGEMENT;
                $run->report = ['preflight' => $report->toArray()];
                $run->save();

                return;
            }

            $run->status = MigrationRun::STATUS_RUNNING;
            $run->started_at = now();

            // Captured before the first write; `:reset` has no other way
            // to tell what this run created.
            $run->report = ['preflight' => $report->toArray(), 'baseline' => Baseline::capture()];
            $run->save();
        }

        $context = new MigrationContext(
            run: $run,
            source: $source,
            idMap: new IdMap($run->id),
            readChunk: (int) config('v1-migration.chunk.read', 1000),
            insertChunk: (int) config('v1-migration.chunk.insert', 500),
        );

        try {
            $finished = (new MigrationRunner(PhaseRegistry::all()))->run($context, self::BUDGET_SECONDS);
        } catch (Throwable $e) {
            // The runner has already written the failure onto the run;
            // rethrowing would only add a failed_jobs row saying the
            // same thing in a place nobody is looking.
            report($e);

            return;
        }

        if (! $finished) {
            self::dispatch($this->runId);
        }
    }

    private function fail(MigrationRun $run, string $message): void
    {
        $run->status = MigrationRun::STATUS_FAILED;
        $run->error = $message;
        $run->finished_at = now();
        $run->save();
    }
}
