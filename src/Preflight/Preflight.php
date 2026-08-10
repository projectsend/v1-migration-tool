<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

use ProjectSend\V1Migration\Source\MigrationSource;

/**
 * Everything checked before a run, in the order that fails fastest.
 *
 * The host is examined before the source on purpose. A missing column or
 * an install that already has content are cheap to detect and make every
 * other question moot — there is no point spending a minute scanning
 * 200,000 v1 file rows to report on encryption when the run could never
 * have started.
 */
final class Preflight
{
    public function __construct(
        private readonly HostSchemaCheck $schema = new HostSchemaCheck,
        private readonly FreshInstallCheck $fresh = new FreshInstallCheck,
        private readonly SourcePreflight $source = new SourcePreflight,
    ) {}

    public function run(?MigrationSource $source = null): PreflightReport
    {
        $findings = [...$this->schema->run(), ...$this->fresh->run()];

        if ($findings !== [] || $source === null) {
            return new PreflightReport($findings);
        }

        return new PreflightReport([...$findings, ...$this->source->run($source)]);
    }
}
