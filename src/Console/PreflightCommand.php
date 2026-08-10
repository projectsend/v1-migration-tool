<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Console\Command;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Preflight\Finding;
use ProjectSend\V1Migration\Preflight\Preflight;
use ProjectSend\V1Migration\Preflight\PreflightReport;
use ProjectSend\V1Migration\Source\SourceFactory;
use Throwable;

/**
 * Looks at a v1 install and this one, and says what would happen.
 *
 * Writes nothing to either side. Safe to run against production as often
 * as you like, which is the point — the answer to "can we migrate?"
 * should not require committing to migrating.
 */
final class PreflightCommand extends Command
{
    use ResolvesSource;

    protected $signature = 'projectsend:migrate:preflight
        {--bundle= : Path to a bundle produced by projectsend-v1-export.php}
        {--v1-path= : Path to a ProjectSend v1 install on this machine}
        {--db-host= : Override the database host from the v1 config}
        {--db-port= : Override the database port}
        {--db-name= : Override the database name}
        {--db-user= : Override the database user}
        {--db-password= : Override the database password}
        {--prefix= : Override the v1 table prefix}';

    protected $description = 'Check a ProjectSend v1 install against this one without changing anything';

    public function handle(Preflight $preflight, SourceFactory $sources): int
    {
        try {
            [$mode, $source] = $this->resolveSource();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // A throwaway run, never saved: SourceFactory reads its source
        // description from a run row, and preflight has no reason to
        // leave one behind.
        $run = new MigrationRun(['mode' => $mode, 'source' => $source, 'options' => []]);

        try {
            $report = $preflight->run($sources->make($run));
        } catch (Throwable $e) {
            $this->error('Could not read the source: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->render($report);

        return $report->isBlocked() ? self::FAILURE : self::SUCCESS;
    }

    public function render(PreflightReport $report): void
    {
        foreach ($report->notes() as $finding) {
            $this->line($finding->message);
        }

        foreach ($report->acknowledgements() as $finding) {
            $this->newLine();
            $this->warn($finding->message);
        }

        foreach ($report->blockers() as $finding) {
            $this->newLine();
            $this->error($finding->message);

            foreach ((array) ($finding->context['sample'] ?? []) as $sample) {
                $this->line('    '.(is_scalar($sample) ? (string) $sample : json_encode($sample)));
            }
        }

        $this->newLine();

        if ($report->isBlocked()) {
            $this->error(sprintf(
                '%d problem(s) must be fixed in the v1 install before importing.',
                count($report->blockers()),
            ));

            return;
        }

        if ($report->needsAcknowledgement()) {
            $this->warn(sprintf(
                'Ready to import. %d thing(s) above have no equivalent in v2 and will be skipped — re-run the import with --accept-skips once you have read them.',
                count($report->acknowledgements()),
            ));

            return;
        }

        $this->info('Ready to import.');
    }

    /**
     * @return list<Finding>
     */
    public function blockers(PreflightReport $report): array
    {
        return $report->blockers();
    }
}
