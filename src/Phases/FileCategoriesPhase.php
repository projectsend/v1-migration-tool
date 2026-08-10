<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * Which categories each file is in.
 *
 * A straight pivot in both versions — the only wrinkle is that v1's
 * category tree was flattened on the way in, so two v1 categories that
 * collapsed to the same v2 one would produce duplicate pivot rows. The
 * unique index catches that and `insertOrIgnore` makes it a no-op rather
 * than a failed chunk.
 */
final class FileCategoriesPhase extends TablePhase
{
    public function key(): string
    {
        return 'file_categories';
    }

    public function label(): string
    {
        return 'File categories';
    }

    protected function table(): string
    {
        return V1Tables::CATEGORIES_RELATIONS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_CATEGORY);

        $fileIds = $context->idMap->lookupMany(
            MigrationIdMap::ENTITY_FILE,
            array_map(static fn (array $row): int => (int) $row['file_id'], $rows),
        );

        $now = now();
        $insert = [];

        foreach ($rows as $row) {
            $fileId = $fileIds[(int) $row['file_id']] ?? null;
            $categoryId = $context->idMap->lookup(MigrationIdMap::ENTITY_CATEGORY, (int) $row['cat_id']);

            if ($fileId === null || $categoryId === null) {
                $context->skipped($this->key(), 'the file or category was not imported');

                continue;
            }

            $insert[] = [
                'category_id' => $categoryId,
                'file_id' => $fileId,
                'created_at' => $row['timestamp'] ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::CATEGORY_FILE)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }
}
