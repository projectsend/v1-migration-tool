<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Console\Command;
use ProjectSend\V1Migration\Files\FileTransfer;
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
 * Imports a ProjectSend v1 install into this one.
 *
 * Runs the phases to completion in this process rather than queueing
 * them: on the command line there is no worker to be recycled and no
 * page waiting for a response, so the budget the queued path needs does
 * not apply. The chunking underneath is the same either way, which is
 * what makes a run started here resumable and inspectable in the
 * interface.
 *
 * Preflight runs first, always, and its blockers are not overridable
 * from here. Acknowledgements are: `--accept-skips` is the operator
 * saying they have read what v2 cannot take.
 */
final class ImportCommand extends Command
{
    use ResolvesSource;

    protected $signature = 'projectsend:migrate:import
        {--bundle= : Path to a bundle produced by projectsend-v1-export.php}
        {--v1-path= : Path to a ProjectSend v1 install on this machine}
        {--db-host= : Override the database host from the v1 config}
        {--db-port= : Override the database port}
        {--db-name= : Override the database name}
        {--db-user= : Override the database user}
        {--db-password= : Override the database password}
        {--prefix= : Override the v1 table prefix}
        {--files=copy : How to move file bytes: hardlink, copy, move or defer}
        {--history=full : Import download and activity history: full or none}
        {--no-checksums : Skip checksumming imported files (faster, leaves the column empty)}
        {--accept-skips : Proceed even though some things have no v2 equivalent}';

    protected $description = 'Import a ProjectSend v1 install into this one';

    public function handle(Preflight $preflight, SourceFactory $sources, PreflightCommand $renderer): int
    {
        $strategy = is_string($this->option('files')) ? (string) $this->option('files') : '';

        if (! in_array($strategy, FileTransfer::strategies(), true)) {
            $this->error('--files must be one of: '.implode(', ', FileTransfer::strategies()));

            return self::FAILURE;
        }

        try {
            [$mode, $sourceDescription] = $this->resolveSource();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = new MigrationRun([
            'status' => MigrationRun::STATUS_PENDING,
            'mode' => $mode,
            'source' => $sourceDescription,
            'options' => [
                'files' => $strategy,
                'history' => $this->option('history') === 'none' ? 'none' : 'full',
                'checksums' => ! $this->option('no-checksums'),
            ],
        ]);

        try {
            $source = $sources->make($run);
            $report = $preflight->run($source);
        } catch (Throwable $e) {
            $this->error('Could not read the source: '.$e->getMessage());

            return self::FAILURE;
        }

        $renderer->setOutput($this->getOutput());
        $renderer->render($report);

        if ($report->isBlocked()) {
            return self::FAILURE;
        }

        if ($report->needsAcknowledgement() && ! $this->option('accept-skips')) {
            $this->error('Re-run with --accept-skips once you have read the warnings above.');

            return self::FAILURE;
        }

        $run->status = MigrationRun::STATUS_RUNNING;
        $run->started_at = now();
        // Captured before the first write, and the only thing that makes
        // `:reset` exact — see Host\Baseline.
        $run->report = ['preflight' => $report->toArray(), 'baseline' => Baseline::capture()];
        $run->save();

        $context = new MigrationContext(
            run: $run,
            source: $source,
            idMap: new IdMap($run->id),
            readChunk: (int) config('v1-migration.chunk.read', 1000),
            insertChunk: (int) config('v1-migration.chunk.insert', 500),
        );

        $runner = new MigrationRunner(PhaseRegistry::all());

        $this->newLine();
        $this->info('Importing…');

        try {
            // No time budget on the command line: there is no worker
            // being recycled underneath, so let it run to the end.
            $runner->run($context, PHP_INT_MAX);
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());
            $this->line('Run `projectsend:migrate:reset` before trying again — a partial import cannot be resumed.');

            return self::FAILURE;
        }

        $this->renderCounts($run);

        return self::SUCCESS;
    }

    private function renderCounts(MigrationRun $run): void
    {
        $this->newLine();

        foreach ($run->report ?? [] as $phase => $values) {
            if ($phase === 'preflight' || ! is_array($values)) {
                continue;
            }

            $imported = $values['imported']
                ?? $values['settings_written']
                ?? $values['granted']
                ?? (isset($values['staff']) || isset($values['clients'])
                    ? (int) ($values['staff'] ?? 0) + (int) ($values['clients'] ?? 0)
                    : null);

            if ($imported !== null) {
                $this->line(sprintf('  %-26s %s', $phase, number_format((int) $imported)));
            }

            foreach ((array) ($values['skipped'] ?? []) as $reason => $count) {
                $this->line(sprintf('  %-26s %s skipped — %s', '', number_format((int) $count), $reason));
            }
        }

        $this->newLine();
        $this->info("Import complete. Run number {$run->id}; see it at /system/migrate.");
        $this->warn('Everyone now signs in with their email address rather than their username — tell them before they try.');
    }
}
