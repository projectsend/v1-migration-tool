<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Models\MigrationRun;

/**
 * Undoes an import.
 *
 * The recovery path for a run that went wrong, and the reason the tool
 * can be fresh-install-only without being a one-shot gamble: try it,
 * look at the result, reset, adjust, try again.
 *
 * Everything created above the run's recorded baseline is deleted, table
 * by table, in reverse dependency order. Rows that were already there —
 * the setup administrator, the roles the host seeds at boot — are below
 * the baseline and are never touched.
 *
 * **`--files=move` cannot be undone.** Moving takes the bytes out of the
 * v1 install; deleting the v2 rows afterwards leaves nothing anywhere.
 * The command says so before it does anything rather than after.
 */
final class ResetCommand extends Command
{
    protected $signature = 'projectsend:migrate:reset
        {--run= : Which run to undo. Defaults to the most recent}
        {--drop : Also drop this package\'s own tables, for uninstalling}
        {--force : Do not ask}';

    protected $description = 'Undo an import, returning this install to how it was before';

    public function handle(): int
    {
        $run = $this->resolveRun();

        if ($run === null) {
            $this->info('No import has been run here; nothing to undo.');

            return self::SUCCESS;
        }

        // A run that was refused at preflight, or is still waiting to be
        // acknowledged, never wrote anything — there is no baseline
        // because there was nothing to take one of. Demanding one would
        // leave a blocked run permanently stuck on the screen with no way
        // to dismiss it and start again.
        if ($run->started_at === null) {
            $run->idMap()->delete();
            $run->delete();

            $this->info("Run {$run->id} never started importing; cleared it.");

            return self::SUCCESS;
        }

        $baseline = $run->report['baseline'] ?? null;

        if (! is_array($baseline)) {
            $this->error(
                "Run {$run->id} has no baseline recorded, so there is no safe way to tell what it created. ".
                'Undo it by restoring your database backup.',
            );

            return self::FAILURE;
        }

        if (($run->options['files'] ?? null) === FileTransfer::MOVE) {
            $this->warn('That run used --files=move, which took the file bytes out of the v1 install.');
            $this->warn('Undoing it deletes them from here too, and they will not be anywhere else.');
        }

        if (! $this->option('force') && ! $this->confirm("Undo import run {$run->id}?", false)) {
            return self::FAILURE;
        }

        $this->deleteFiles((array) $baseline);

        foreach (HostTables::deleteOrder() as $table) {
            // A table with no baseline is one this host does not have — an
            // optional feature it is older than, which the run skipped and
            // therefore put nothing in. Deleting from it would raise "no
            // such table" at the exact moment somebody is trying to put
            // their installation back.
            if (! array_key_exists($table, $baseline)) {
                continue;
            }

            $from = (int) $baseline[$table];
            $deleted = DB::table($table)->where('id', '>', $from)->delete();

            if ($deleted > 0) {
                $this->line(sprintf('  %-30s %s row(s)', $table, number_format($deleted)));
            }
        }

        $run->idMap()->delete();
        $run->delete();

        $this->info('Import undone.');

        if ($this->option('drop')) {
            Schema::dropIfExists('v1_migration_id_map');
            Schema::dropIfExists('v1_migration_runs');
            $this->warn('Dropped this package\'s tables. The v1 → v2 id map is gone, so old download.php links can no longer be resolved.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $baseline
     */
    private function deleteFiles(array $baseline): void
    {
        $from = (int) ($baseline[HostTables::FILES] ?? 0);
        $disk = Storage::disk(FileTransfer::DISK);
        $deleted = 0;

        DB::table(HostTables::FILES)
            ->where('id', '>', $from)
            ->select('path', 'disk')
            ->orderBy('id')
            ->chunk(500, function ($files) use ($disk, &$deleted): void {
                foreach ($files as $file) {
                    if ($disk->delete((string) $file->path)) {
                        $deleted++;
                    }
                }
            });

        if ($deleted > 0) {
            $this->line(sprintf('  %-30s %s file(s)', 'storage', number_format($deleted)));
        }
    }

    private function resolveRun(): ?MigrationRun
    {
        $id = $this->option('run');

        return is_string($id) && $id !== ''
            ? MigrationRun::find((int) $id)
            : MigrationRun::query()->latest('id')->first();
    }
}
