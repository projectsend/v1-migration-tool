<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Models\MigrationRun;

/**
 * Removes the duplicate download rows left by an import run before this
 * tool learned that v1 records a download twice.
 *
 * Until then `DownloadsPhase` imported v1's downloads ledger and
 * `ActivityLogPhase` imported the actions_log entries describing the
 * same downloads, so an install migrated by an older release reports
 * close to twice the downloads it really had — on the file details
 * panel, on /downloads, and on the dashboard chart. Installs migrated
 * after the fix have nothing to repair, and this says so.
 *
 * ### It reports by default and deletes only when told to
 *
 * This removes rows from an audit log, which is the last table in the
 * application anybody should be casual about. So the default is a
 * report: what it found, what it would remove, and what would survive.
 * `--repair` is the only thing that deletes, and it still asks.
 *
 * ### Every deletion is pair-verified, which is the whole safety argument
 *
 * The tempting predicate is "a migrated download row that carries a
 * `subject_name`", because the old `DownloadsPhase` left that column
 * null and `ActivityLogPhase` filled it from v1. It is also a trap: the
 * same fix that stopped the duplication *also* made `DownloadsPhase`
 * populate `subject_name`, so on an install migrated by a current
 * release that predicate matches every download row there is and the
 * "repair" deletes the entire download history.
 *
 * So nothing is deleted on the strength of its own shape. A row is
 * removed only when its surviving counterpart is demonstrably sitting
 * there — same file, same instant, same action, and carrying the null
 * `subject_name` that only the old ledger import wrote. That makes the
 * command inert on an install that was never affected, rather than
 * merely unlikely to fire, and it is the difference between a guard and
 * a hope.
 *
 * It also disposes of the case that would otherwise be real data loss.
 * `DownloadsPhase` skips a download whose file was not imported, so for
 * those the actions_log row is the *only* record. Having no counterpart,
 * they are kept.
 *
 * ### And it is bounded to what the run itself created
 *
 * Two further conditions that the pair check alone would not give:
 * rows are considered only above the run's recorded baseline, and only
 * with a `created_at` earlier than the moment the run started. Together
 * those exclude everything that was here beforehand and everything the
 * installation has recorded since — a v2 download happening now carries
 * today's timestamp, so it can never be mistaken for imported history
 * however closely its columns happen to match.
 */
final class RepairDownloadsCommand extends Command
{
    protected $signature = 'projectsend:migrate:repair-downloads
        {--run= : Which run to repair. Defaults to the most recent}
        {--repair : Actually delete the duplicates. Without this, only reports}
        {--force : Do not ask}';

    protected $description = 'Remove duplicate download history left by an import run from before the fix';

    /**
     * The two actions an import can write for a download. A share link
     * is not among them: v1 had no such thing, so nothing migrated can
     * carry that action and including it would widen the blast radius
     * for no reason.
     *
     * @var list<string>
     */
    private const DOWNLOAD_ACTIONS = ['file.downloaded', 'public_file.downloaded'];

    public function handle(): int
    {
        $run = $this->resolveRun();

        if ($run === null) {
            $this->info('No import has been run here; nothing to repair.');

            return self::SUCCESS;
        }

        if ($run->started_at === null) {
            $this->info("Run {$run->id} never started importing; nothing to repair.");

            return self::SUCCESS;
        }

        $baseline = $run->report['baseline'][HostTables::ACTIVITY_LOG] ?? null;

        if (! is_int($baseline) && ! is_string($baseline)) {
            $this->error(
                "Run {$run->id} has no activity log baseline recorded, so there is no safe way to tell ".
                'which rows it created. This cannot be repaired automatically.',
            );

            return self::FAILURE;
        }

        $duplicates = $this->duplicates((int) $baseline, $run)->count();
        $surviving = $this->imported((int) $baseline, $run)->count() - $duplicates;

        $this->line("Run {$run->id}, imported {$run->started_at->toDateString()}");
        $this->line('  Migrated download rows:   '.number_format($surviving + $duplicates));
        $this->line('  Duplicates to remove:     '.number_format($duplicates));
        $this->line('  Surviving after repair:   '.number_format($surviving));

        if ($duplicates === 0) {
            $this->info('Nothing to repair — this install has one row per download already.');

            return self::SUCCESS;
        }

        // A run that imported downloads should end with roughly the count
        // v1 held. Removing everything would mean the pair check matched
        // rows that are each other's only copy, which cannot happen by
        // construction — so if it somehow does, stop rather than proceed.
        if ($surviving <= 0) {
            $this->error('Refusing: that would remove every migrated download row, which is not what a duplicate is.');

            return self::FAILURE;
        }

        if (! $this->option('repair')) {
            $this->newLine();
            $this->comment('Reported only. Re-run with --repair to remove them.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently remove {$duplicates} duplicate download row(s)?", false)) {
            return self::FAILURE;
        }

        $removed = $this->remove((int) $baseline, $run, $duplicates);

        $this->info("Removed {$removed} duplicate download row(s).");

        return self::SUCCESS;
    }

    /**
     * Every download row this run imported, duplicates included.
     */
    private function imported(int $baseline, MigrationRun $run): Builder
    {
        return DB::table(HostTables::ACTIVITY_LOG)
            ->whereIn('action', self::DOWNLOAD_ACTIONS)
            ->where('id', '>', $baseline)
            ->where('created_at', '<', $run->started_at);
    }

    /**
     * The ones that are a second copy of a row that is still there.
     */
    private function duplicates(int $baseline, MigrationRun $run): Builder
    {
        return $this->imported($baseline, $run)
            // Written by ActivityLogPhase, which snapshotted v1's
            // affected_file_name. The old DownloadsPhase wrote null here,
            // which is what the counterpart is recognised by below.
            ->whereNotNull('subject_name')
            ->whereExists(function (Builder $query) use ($baseline, $run): void {
                $query->select(DB::raw(1))
                    ->from(HostTables::ACTIVITY_LOG, 'ledger')
                    ->whereColumn('ledger.subject_id', HostTables::ACTIVITY_LOG.'.subject_id')
                    ->whereColumn('ledger.created_at', HostTables::ACTIVITY_LOG.'.created_at')
                    ->whereColumn('ledger.action', HostTables::ACTIVITY_LOG.'.action')
                    ->whereNull('ledger.subject_name')
                    ->where('ledger.id', '>', $baseline)
                    ->where('ledger.created_at', '<', $run->started_at);
            });
    }

    /**
     * Deleted in batches by id rather than in one statement: this is a
     * six-figure delete on a busy installation's audit log, and one
     * transaction that size holds locks for as long as it takes.
     *
     * Capped at the number the report showed. If the set has somehow
     * grown between reporting and deleting, that is a reason to stop and
     * look rather than to keep going.
     */
    private function remove(int $baseline, MigrationRun $run, int $expected): int
    {
        $removed = 0;

        while ($removed < $expected) {
            $ids = $this->duplicates($baseline, $run)->limit(1000)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $removed += DB::table(HostTables::ACTIVITY_LOG)->whereIn('id', $ids)->delete();
        }

        return $removed;
    }

    private function resolveRun(): ?MigrationRun
    {
        $id = $this->option('run');

        // Matches ResetCommand: find() is typed as possibly returning a
        // collection, which it cannot here, so the id path goes through
        // the same narrowing that one uses.
        return is_string($id) && $id !== ''
            ? MigrationRun::find((int) $id)
            : MigrationRun::query()->latest('id')->first();
    }
}
