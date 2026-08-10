<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;

/**
 * Categories, flattened.
 *
 * v1's categories are a tree; v2's are deliberately flat — its migration
 * says so out loud ("Flat, cross-cutting labels for files (folders
 * provide hierarchy)"), and there is no parent_id to import into.
 *
 * So the hierarchy has to collapse, and the question is only how loudly.
 * A leaf keeps its own name when that name is still free. When it is
 * not — v1 installs routinely have "2024 › Invoices" and "2025 ›
 * Invoices" — the parent path is prefixed, so both survive and stay
 * distinguishable instead of one of them silently winning a unique
 * constraint. Every renamed category is counted in the report.
 *
 * The whole table is read into memory first. It has to be: a child can
 * appear before its parent, and the name a child ends up with depends on
 * its ancestors. Categories are made by hand, so this is hundreds of
 * rows, not millions.
 */
final class CategoriesPhase implements Phase
{
    public function key(): string
    {
        return 'categories';
    }

    public function label(): string
    {
        return 'Categories';
    }

    public function total(MigrationContext $context): int
    {
        return $context->source->count(V1Tables::CATEGORIES);
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($cursor > 0) {
            return null;
        }

        $categories = $this->readAll($context);

        if ($categories === []) {
            return null;
        }

        $taken = DB::table(HostTables::CATEGORIES)
            ->pluck('name')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        $taken = array_flip($taken);

        $now = now();

        foreach ($categories as $sourceId => $category) {
            $renamed = false;
            $name = $this->uniqueName($categories, $sourceId, $taken, $renamed);

            $id = (int) DB::table(HostTables::CATEGORIES)->insertGetId([
                'name' => $name,
                'color' => 'gray',
                'created_at' => $category['timestamp'] ?? $now,
                'updated_at' => $now,
            ]);

            $taken[$name] = true;

            $context->idMap->record(MigrationIdMap::ENTITY_CATEGORY, $sourceId, $id);
            $context->count($this->key(), 'imported');

            if ($renamed) {
                $context->count($this->key(), 'renamed_to_keep_apart');
            }
        }

        $context->idMap->flush();

        $depth = $this->deepestNesting($categories);

        if ($depth > 1) {
            $context->set($this->key(), 'v1_tree_depth_flattened', $depth);
        }

        return 1;
    }

    /**
     * @return array<int, array{name: string, parent: int|null, timestamp: mixed}>
     */
    private function readAll(MigrationContext $context): array
    {
        $categories = [];
        $afterId = 0;

        while (($rows = $context->source->rows(V1Tables::CATEGORIES, $afterId, $context->readChunk)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $parent = $row['parent'] === null ? null : (int) $row['parent'];

                $categories[$afterId] = [
                    'name' => LegacyText::line((string) ($row['name'] ?? '')) ?: 'Category '.$afterId,
                    'parent' => $parent > 0 ? $parent : null,
                    'timestamp' => $row['timestamp'] ?? null,
                ];
            }
        }

        return $categories;
    }

    /**
     * The leaf name, or the ancestor path when the leaf name is taken.
     *
     * @param  array<int, array{name: string, parent: int|null, timestamp: mixed}>  $categories
     * @param  array<string, mixed>  $taken
     */
    private function uniqueName(array $categories, int $id, array $taken, bool &$renamed): string
    {
        $renamed = false;
        $name = $categories[$id]['name'];

        if (! isset($taken[$name])) {
            return $name;
        }

        $renamed = true;
        $parts = [$name];
        $parent = $categories[$id]['parent'];
        $guard = 0;

        // A self-referencing table with no constraint against cycles;
        // ten levels is far past any real category tree and stops a
        // corrupt one from hanging the import.
        while ($parent !== null && isset($categories[$parent]) && $guard++ < 10) {
            array_unshift($parts, $categories[$parent]['name']);
            $candidate = implode(' / ', $parts);

            if (! isset($taken[$candidate])) {
                return $candidate;
            }

            $parent = $categories[$parent]['parent'];
        }

        $candidate = implode(' / ', $parts);
        $suffix = 2;

        while (isset($taken[$candidate.' ('.$suffix.')'])) {
            $suffix++;
        }

        return $candidate.' ('.$suffix.')';
    }

    /**
     * @param  array<int, array{name: string, parent: int|null, timestamp: mixed}>  $categories
     */
    private function deepestNesting(array $categories): int
    {
        $deepest = 1;

        foreach (array_keys($categories) as $id) {
            $depth = 1;
            $parent = $categories[$id]['parent'];

            while ($parent !== null && isset($categories[$parent]) && $depth < 10) {
                $depth++;
                $parent = $categories[$parent]['parent'];
            }

            $deepest = max($deepest, $depth);
        }

        return $deepest;
    }
}
