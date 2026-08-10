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
 * Categories, flattened onto their full path.
 *
 * v1's categories are a tree; v2's are deliberately flat — its migration
 * says so out loud ("Flat, cross-cutting labels for files (folders
 * provide hierarchy)"), and there is no parent_id to import into. So the
 * hierarchy has to collapse, and the only real question is what the
 * names become.
 *
 * **A category that had a parent takes its whole ancestry as its name:**
 * `Clients / Acme / Invoices`. A root category keeps its bare name.
 *
 * The alternative — keep the leaf name and qualify it only when it
 * collides — produces shorter names and was what this did first. It was
 * dropped because *which* of three `Invoices` categories got to keep the
 * bare name was decided by v1's row order: an administrator would see
 * `Invoices`, `Globex / Invoices` and `Archive / Invoices` side by side
 * with nothing explaining why one is special. Qualifying all of them
 * costs some verbosity and buys an install where every category name
 * means the same kind of thing, and where the tree that used to exist is
 * still readable. Renaming afterwards is one screen.
 *
 * Nothing merges and nothing is dropped: every v1 category becomes
 * exactly one v2 category, so no file loses a tag.
 *
 * The whole table is read into memory first. It has to be — a child can
 * appear before its parent, and a name depends on every ancestor.
 * Categories are made by hand, so this is hundreds of rows, not
 * millions.
 */
final class CategoriesPhase implements Phase
{
    private const SEPARATOR = ' / ';

    /**
     * v2's categories.name is a varchar(255). A deep tree of long names
     * can exceed that, and a database error three phases into an import
     * is a poor way to find out.
     */
    private const MAX_NAME = 255;

    /**
     * Stands in for the ancestors dropped to fit MAX_NAME.
     */
    private const ELLIPSIS = '…';

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

        /** @var array<string, true> $taken */
        $taken = array_fill_keys(
            DB::table(HostTables::CATEGORIES)->pluck('name')->map(strval(...))->all(),
            true,
        );

        $now = now();
        $nested = 0;
        $shortened = 0;
        $duplicates = 0;
        $orphaned = 0;

        foreach ($categories as $sourceId => $category) {
            $wasShortened = false;
            $parts = $this->ancestry($categories, $sourceId, $orphaned);
            $path = $this->fit($parts, $wasShortened);
            $name = $this->unique($path, $taken, $duplicates);

            if ($wasShortened) {
                $shortened++;
            }

            $id = (int) DB::table(HostTables::CATEGORIES)->insertGetId([
                'name' => $name,
                'color' => 'gray',
                'created_at' => $category['timestamp'] ?? $now,
                'updated_at' => $now,
            ]);

            $taken[$name] = true;

            $context->idMap->record(MigrationIdMap::ENTITY_CATEGORY, $sourceId, $id);
            $context->count($this->key(), 'imported');

            if (count($parts) > 1) {
                $nested++;
            }
        }

        $context->idMap->flush();

        if ($nested > 0) {
            $context->count($this->key(), 'renamed_to_their_full_path', $nested);
            $context->set($this->key(), 'v1_tree_depth', $this->deepestNesting($categories));
        }

        if ($shortened > 0) {
            $context->count($this->key(), 'name_too_long_ancestors_dropped', $shortened);
        }

        if ($duplicates > 0) {
            $context->count($this->key(), 'identical_paths_suffixed', $duplicates);
        }

        if ($orphaned > 0) {
            $context->skipped($this->key(), 'parent category missing; imported without its ancestry');
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
                    'parent' => $parent !== null && $parent > 0 ? $parent : null,
                    'timestamp' => $row['timestamp'] ?? null,
                ];
            }
        }

        return $categories;
    }

    /**
     * Root-first list of names, from the outermost ancestor down to this
     * category.
     *
     * `tbl_categories.parent` is a self-reference with no constraint
     * against cycles and no foreign key, so both a loop and a parent id
     * pointing at nothing are possible in a real install. A loop stops at
     * the first repeat; a dangling parent stops the walk and is counted,
     * which leaves the category with a shorter path rather than failing
     * the run.
     *
     * @param  array<int, array{name: string, parent: int|null, timestamp: mixed}>  $categories
     * @return list<string>
     */
    private function ancestry(array $categories, int $id, int &$orphaned): array
    {
        $parts = [];
        $seen = [];
        $current = $id;

        while ($current !== null && ! isset($seen[$current])) {
            if (! isset($categories[$current])) {
                $orphaned++;

                break;
            }

            $seen[$current] = true;
            array_unshift($parts, $categories[$current]['name']);
            $current = $categories[$current]['parent'];
        }

        return $parts;
    }

    /**
     * The path as a name, short enough for the column.
     *
     * Ancestors are dropped from the front rather than the name being
     * truncated at the end: the deepest part is the specific one, and the
     * one an administrator will recognise in a filter.
     *
     * @param  list<string>  $parts
     */
    private function fit(array $parts, bool &$shortened): string
    {
        $shortened = false;
        $name = implode(self::SEPARATOR, $parts);

        if (mb_strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        $shortened = true;

        while (count($parts) > 1) {
            array_shift($parts);
            $name = self::ELLIPSIS.self::SEPARATOR.implode(self::SEPARATOR, $parts);

            if (mb_strlen($name) <= self::MAX_NAME) {
                return $name;
            }
        }

        return mb_substr($parts[0] ?? 'Category', 0, self::MAX_NAME);
    }

    /**
     * v2's categories.name is unique and v1's is not — two siblings can
     * share a name, which makes two identical paths. A suffix keeps both
     * rather than failing the chunk, so no file loses its tag.
     *
     * @param  array<string, true>  $taken
     */
    private function unique(string $name, array $taken, int &$duplicates): string
    {
        if (! isset($taken[$name])) {
            return $name;
        }

        $duplicates++;
        $suffix = 2;

        while (true) {
            $marker = ' ('.$suffix.')';
            $candidate = mb_strlen($name) + mb_strlen($marker) > self::MAX_NAME
                ? mb_substr($name, 0, self::MAX_NAME - mb_strlen($marker)).$marker
                : $name.$marker;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }

            $suffix++;
        }
    }

    /**
     * @param  array<int, array{name: string, parent: int|null, timestamp: mixed}>  $categories
     */
    private function deepestNesting(array $categories): int
    {
        $deepest = 1;
        $orphaned = 0;

        foreach (array_keys($categories) as $id) {
            $deepest = max($deepest, count($this->ancestry($categories, $id, $orphaned)));
        }


        return $deepest;
    }
}
