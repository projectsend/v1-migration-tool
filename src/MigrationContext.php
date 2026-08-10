<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration;

use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\MigrationSource;
use ProjectSend\V1Migration\Transform\LegacyClock;

/**
 * Everything a phase needs, and nothing it should reach around for.
 *
 * Phases are handed this rather than resolving services themselves, so
 * that a run's source, id map and options are unambiguous — there is no
 * "current" migration anywhere in the container, and two runs in one
 * process would otherwise be indistinguishable.
 *
 * `note()` is how a phase says something happened that the operator
 * should see. It accumulates into the run's report rather than the
 * activity log, because the activity log's Action enum is closed to
 * packages and, more importantly, because "3,815 rows of an activity
 * code v2 has no equivalent for" is a fact about the *migration*, not
 * about the install.
 */
final class MigrationContext
{
    /**
     * Running totals, summed into the report on flush.
     *
     * @var array<string, array<string, int>>
     */
    private array $counters = [];

    /**
     * Reason => how many rows it applied to, per phase.
     *
     * @var array<string, array<string, int>>
     */
    private array $skips = [];

    /**
     * Facts rather than tallies — replaced on flush, never added up.
     * Kept apart from the counters because they are not numbers: the
     * settings phase records a map of v1 option name => why it was not
     * carried, and summing that turned every reason into 0.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $values = [];

    /**
     * How many source rows are read per pass.
     *
     * @var int<1, max>
     */
    public readonly int $readChunk;

    /**
     * How many rows go into one multi-row INSERT.
     *
     * @var int<1, max>
     */
    public readonly int $insertChunk;

    /**
     * Turns v1's naive wall-clock timestamps into UTC. Built once here
     * rather than per phase because the source zone comes off the
     * manifest, and manifest() counts every table on the way past — a
     * cost worth paying once a run, not once a row. See LegacyClock for
     * why every timestamp has to go through it.
     */
    public readonly LegacyClock $clock;

    public function __construct(
        public readonly MigrationRun $run,
        public readonly MigrationSource $source,
        public readonly IdMap $idMap,
        int $readChunk = 1000,
        int $insertChunk = 500,
        ?LegacyClock $clock = null,
    ) {
        $this->clock = $clock ?? new LegacyClock($source->manifest()->timezone);

        // Clamped rather than validated: these come from configuration an
        // operator can edit, and a zero would turn every chunked loop into
        // an infinite one.
        $this->readChunk = max(1, $readChunk);
        $this->insertChunk = max(1, $insertChunk);
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->run->options;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->run->options[$key] ?? $default;
    }

    /**
     * Add to a counter in this phase's section of the report.
     */
    public function count(string $phase, string $key, int $by = 1): void
    {
        $this->counters[$phase][$key] = ($this->counters[$phase][$key] ?? 0) + $by;
    }

    /**
     * Record a reason something was skipped, with a running tally per
     * distinct reason. Counting rather than listing keeps a report
     * readable when 200,000 rows share one explanation.
     */
    public function skipped(string $phase, string $reason): void
    {
        $this->skips[$phase][$reason] = ($this->skips[$phase][$reason] ?? 0) + 1;
    }

    public function set(string $phase, string $key, mixed $value): void
    {
        $this->values[$phase][$key] = $value;
    }

    /**
     * Flush accumulated notes onto the run row. Called by the runner
     * after each chunk, so a report is useful while a run is still going
     * and survives a process that dies mid-phase.
     */
    public function flushNotes(): void
    {
        if ($this->counters === [] && $this->skips === [] && $this->values === []) {
            return;
        }

        $report = $this->run->report ?? [];

        foreach ($this->counters as $phase => $counts) {
            foreach ($counts as $key => $by) {
                $report[$phase][$key] = (int) ($report[$phase][$key] ?? 0) + $by;
            }
        }

        foreach ($this->skips as $phase => $reasons) {
            foreach ($reasons as $reason => $by) {
                $report[$phase]['skipped'][$reason] = (int) ($report[$phase]['skipped'][$reason] ?? 0) + $by;
            }
        }

        foreach ($this->values as $phase => $values) {
            foreach ($values as $key => $value) {
                $report[$phase][$key] = $value;
            }
        }

        $this->run->report = $report;
        $this->run->save();

        $this->counters = [];
        $this->skips = [];
        $this->values = [];
    }

}
