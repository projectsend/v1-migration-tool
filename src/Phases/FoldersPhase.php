<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;
use ProjectSend\V1Migration\Transform\SlugReserver;

/**
 * The folder tree.
 *
 * **Nothing imports as public, and that is not a shortcut.** v1's
 * `folders.public` column is 1 on every row of every install, because
 * `Folder::create()` ignores the value it is handed and hardcodes it —
 * the column records a bug, not an intention. v2's `folders.public`
 * genuinely publishes a folder to anyone with the link. Copying the
 * column across would take an install's entire folder tree public in one
 * step, which is the worst thing this tool could do. Everything arrives
 * private; the report says so, and says how many folders were affected
 * so an administrator can re-publish the handful they meant.
 *
 * Parents are imported before children, and the whole table is read into
 * memory to do it. v2 stores a materialized ancestor path (`/3/7/`) that
 * can only be computed once the parent's own path and new id are known,
 * and v1 gives no guarantee that a child's id is higher than its
 * parent's. Folders are made by hand, so this is hundreds of rows.
 */
final class FoldersPhase implements Phase
{
    public function key(): string
    {
        return 'folders';
    }

    public function label(): string
    {
        return 'Folders';
    }

    public function total(MigrationContext $context): int
    {
        return $context->source->count(V1Tables::FOLDERS);
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($cursor > 0) {
            return null;
        }

        $folders = $this->readAll($context);

        if ($folders === []) {
            return null;
        }

        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $slugs = SlugReserver::seededFrom(HostTables::FOLDERS, 'folder');
        $now = now();

        /** @var array<int, string> $paths v1 folder id => its v2 subtree prefix */
        $paths = [];
        $publicInV1 = 0;

        foreach ($this->inParentOrder($folders) as $sourceId) {
            $folder = $folders[$sourceId];
            $parent = $folder['parent'];
            $parentId = $parent === null
                ? null
                : $context->idMap->lookup(MigrationIdMap::ENTITY_FOLDER, $parent);

            if ($parent !== null && $parentId === null) {
                // Its parent was not imported, which for a tree read
                // whole can only mean a dangling parent id. Re-rooting
                // keeps the folder and its files reachable.
                $context->skipped($this->key(), 'parent folder missing; folder re-rooted');
            }

            $name = LegacyText::line($folder['name']) ?: 'Folder '.$sourceId;

            $id = (int) DB::table(HostTables::FOLDERS)->insertGetId([
                'name' => $name,
                'parent_id' => $parentId,
                'created_by' => $context->idMap->lookup(MigrationIdMap::ENTITY_USER, $folder['user_id']),
                'public' => false,
                'slug' => $slugs->reserve($name),
                'allow_client_uploads' => false,
                'path' => $parentId === null ? '/' : ($paths[$parent] ?? '/'),
                'created_at' => $folder['timestamp'] ?? $now,
                'updated_at' => $now,
            ]);

            $paths[$sourceId] = ($parentId === null ? '/' : ($paths[$parent] ?? '/')).$id.'/';

            if ($folder['public']) {
                $publicInV1++;
            }

            $context->idMap->record(MigrationIdMap::ENTITY_FOLDER, $sourceId, $id);
            $context->idMap->flush();
            $context->count($this->key(), 'imported');
        }

        if ($publicInV1 > 0) {
            $context->count($this->key(), 'imported_private_despite_v1_public_flag', $publicInV1);
        }

        return 1;
    }

    /**
     * @return array<int, array{name: string, parent: int|null, user_id: int|null, public: bool, timestamp: mixed}>
     */
    private function readAll(MigrationContext $context): array
    {
        $folders = [];
        $afterId = 0;

        while (($rows = $context->source->rows(V1Tables::FOLDERS, $afterId, $context->readChunk)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $parent = $row['parent'] === null ? null : (int) $row['parent'];

                $folders[$afterId] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'parent' => $parent > 0 ? $parent : null,
                    'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                    'public' => (int) ($row['public'] ?? 0) === 1,
                    'timestamp' => $row['timestamp'] ?? null,
                ];
            }
        }

        return $folders;
    }

    /**
     * Source ids ordered so a folder always follows its parent.
     *
     * @param  array<int, array{name: string, parent: int|null, user_id: int|null, public: bool, timestamp: mixed}>  $folders
     * @return list<int>
     */
    private function inParentOrder(array $folders): array
    {
        $ordered = [];
        $placed = [];

        $place = function (int $id, array $seen) use (&$place, &$ordered, &$placed, $folders): void {
            // A self-referencing parent column with no cycle constraint;
            // a loop would otherwise recurse until the stack gives out.
            if (isset($placed[$id]) || isset($seen[$id]) || ! isset($folders[$id])) {
                return;
            }

            $seen[$id] = true;
            $parent = $folders[$id]['parent'];

            if ($parent !== null) {
                $place($parent, $seen);
            }

            $placed[$id] = true;
            $ordered[] = $id;
        };

        foreach (array_keys($folders) as $id) {
            $place($id, []);
        }

        return $ordered;
    }
}
