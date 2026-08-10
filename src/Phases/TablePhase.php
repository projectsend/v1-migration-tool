<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use ProjectSend\V1Migration\MigrationContext;

/**
 * A phase that walks one v1 table from start to finish.
 *
 * Most of them are. The cursor is the v1 row id, the chunk boundary is
 * the transaction boundary, and the id map is flushed inside it — so a
 * worker that dies between chunks resumes at a consistent point, and one
 * that dies *within* a chunk loses that chunk's work entirely rather
 * than half of it.
 */
abstract class TablePhase implements Phase
{
    abstract protected function table(): string;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    abstract protected function process(MigrationContext $context, array $rows): void;

    public function total(MigrationContext $context): int
    {
        return $context->source->count($this->table());
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        $rows = $context->source->rows($this->table(), $cursor, $context->readChunk);

        if ($rows === []) {
            return null;
        }

        $this->process($context, $rows);
        $context->idMap->flush();

        $last = $rows[count($rows) - 1];

        return (int) $last['id'];
    }
}
