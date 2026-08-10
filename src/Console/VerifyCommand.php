<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\SourceFactory;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Reconciles what was imported against what was there.
 *
 * The question this answers is the only one that matters after a
 * migration: **is anything missing that nobody meant to lose?** Counting
 * rows in v2 is not enough on its own — a phase that silently skipped
 * ten thousand rows still leaves a database that looks populated.
 *
 * So every entity is checked as an equation: imported + deliberately
 * skipped = what v1 had. A number that does not add up is a bug in this
 * tool, and it is reported as one rather than as a warning about the
 * data.
 *
 * The file check is separate and stricter: every imported file row must
 * have bytes at the path it claims. That is the check that catches a
 * transfer strategy failing quietly, and it is the difference between a
 * migration and a database full of broken download links.
 */
final class VerifyCommand extends Command
{
    protected $signature = 'projectsend:migrate:verify
        {--run= : Which run to verify. Defaults to the most recent}
        {--sample=500 : How many files to check on disk. 0 checks all of them}';

    protected $description = 'Check an import against the v1 install it came from';

    /**
     * v1 table => the id-map entity its rows became.
     */
    private const ENTITIES = [
        V1Tables::USERS => MigrationIdMap::ENTITY_USER,
        V1Tables::ROLES => MigrationIdMap::ENTITY_ROLE,
        V1Tables::GROUPS => MigrationIdMap::ENTITY_GROUP,
        V1Tables::CATEGORIES => MigrationIdMap::ENTITY_CATEGORY,
        V1Tables::FOLDERS => MigrationIdMap::ENTITY_FOLDER,
        V1Tables::FILES => MigrationIdMap::ENTITY_FILE,
    ];

    public function handle(SourceFactory $sources): int
    {
        $run = $this->resolveRun();

        if ($run === null) {
            $this->error('No import to verify.');

            return self::FAILURE;
        }

        $source = $sources->make($run);
        $problems = 0;

        $this->line(sprintf('%-14s %8s %10s %8s', '', 'in v1', 'imported', 'skipped'));

        foreach (self::ENTITIES as $table => $entity) {
            $expected = $source->count($table);
            $imported = $this->countMapped($run, $entity, MigrationIdMap::STATUS_IMPORTED);
            $skipped = $this->countMapped($run, $entity, MigrationIdMap::STATUS_SKIPPED);

            $this->line(sprintf(
                '%-14s %8s %10s %8s%s',
                $table,
                number_format($expected),
                number_format($imported),
                number_format($skipped),
                $imported + $skipped === $expected ? '' : '   <- does not add up',
            ));

            if ($imported + $skipped !== $expected) {
                $problems++;
            }
        }

        $problems += $this->verifyFiles($run);

        $this->newLine();

        if ($problems > 0) {
            $this->error("{$problems} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('Everything v1 had is either here or listed as skipped.');

        return self::SUCCESS;
    }

    private function verifyFiles(MigrationRun $run): int
    {
        if (($run->options['files'] ?? null) === FileTransfer::DEFER) {
            $this->newLine();
            $this->warn('That run deferred file bytes, so there is nothing on disk to check yet.');

            return 0;
        }

        $sample = (int) $this->option('sample');
        $disk = Storage::disk(FileTransfer::DISK);

        $query = DB::table(HostTables::FILES)->select('id', 'path')->orderBy('id');
        $checked = 0;
        $missing = [];

        foreach ($sample > 0 ? $query->limit($sample)->get() : $query->cursor() as $file) {
            $checked++;

            if (! $disk->exists((string) $file->path)) {
                $missing[] = (int) $file->id;
            }
        }

        $this->newLine();
        $this->line(sprintf('Checked %s file(s) on disk.', number_format($checked)));

        if ($missing === []) {
            return 0;
        }

        $this->error(sprintf(
            '%d imported file(s) have no bytes at the path they claim, starting with id %d.',
            count($missing),
            $missing[0],
        ));

        return 1;
    }

    private function countMapped(MigrationRun $run, string $entity, string $status): int
    {
        return (int) MigrationIdMap::query()
            ->where('run_id', $run->id)
            ->where('entity', $entity)
            ->where('status', $status)
            ->count();
    }

    private function resolveRun(): ?MigrationRun
    {
        $id = $this->option('run');

        return is_string($id) && $id !== ''
            ? MigrationRun::find((int) $id)
            : MigrationRun::query()->latest('id')->first();
    }
}
