<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration;

use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\MigrationSource;

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
    /** @var array<string, array<string, mixed>> */
    private array $notes = [];

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

    public function __construct(
        public readonly MigrationRun $run,
        public readonly MigrationSource $source,
        public readonly IdMap $idMap,
        int $readChunk = 1000,
        int $insertChunk = 500,
    ) {
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
        $this->notes[$phase][$key] = (int) ($this->notes[$phase][$key] ?? 0) + $by;
    }

    /**
     * Record a reason something was skipped, with a running tally per
     * distinct reason. Counting rather than listing keeps a report
     * readable when 200,000 rows share one explanation.
     */
    public function skipped(string $phase, string $reason): void
    {
        $this->notes[$phase]['skipped'][$reason] = (int) ($this->notes[$phase]['skipped'][$reason] ?? 0) + 1;
    }

    public function set(string $phase, string $key, mixed $value): void
    {
        $this->notes[$phase][$key] = $value;
    }

    /**
     * Flush accumulated notes onto the run row. Called by the runner
     * after each chunk, so a report is useful while a run is still going
     * and survives a process that dies mid-phase.
     */
    public function flushNotes(): void
    {
        if ($this->notes === []) {
            return;
        }

        $merged = $this->run->report ?? [];

        foreach ($this->notes as $phase => $values) {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $inner => $count) {
                        $merged[$phase][$key][$inner] = (int) ($merged[$phase][$key][$inner] ?? 0) + (int) $count;
                    }

                    continue;
                }

                $merged[$phase][$key] = is_int($value)
                    ? (int) ($merged[$phase][$key] ?? 0) + $value
                    : $value;
            }
        }

        $this->run->report = $merged;
        $this->run->save();

        $this->notes = [];
    }
}
