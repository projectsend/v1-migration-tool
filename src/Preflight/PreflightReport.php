<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

/**
 * What preflight found, and whether the run may start.
 *
 * The distinction the whole tool hangs off: a **blocker** is refused
 * outright, an **acknowledgement** is refused until a human says they
 * have read it. Nothing else stops a run.
 */
final class PreflightReport
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(public readonly array $findings) {}

    /**
     * @return list<Finding>
     */
    public function blockers(): array
    {
        return $this->atLevel(Finding::BLOCKER);
    }

    /**
     * @return list<Finding>
     */
    public function acknowledgements(): array
    {
        return $this->atLevel(Finding::ACKNOWLEDGE);
    }

    /**
     * @return list<Finding>
     */
    public function notes(): array
    {
        return $this->atLevel(Finding::NOTE);
    }

    public function isBlocked(): bool
    {
        return $this->blockers() !== [];
    }

    public function needsAcknowledgement(): bool
    {
        return $this->acknowledgements() !== [];
    }

    /**
     * @return list<array{level: string, code: string, message: string, context: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Finding $finding): array => $finding->toArray(), $this->findings);
    }

    /**
     * @return list<Finding>
     */
    private function atLevel(string $level): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->level === $level,
        ));
    }
}
