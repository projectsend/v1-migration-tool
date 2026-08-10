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
 * Client groups.
 *
 * v1's `public_token` is not carried. It is the token in
 * `groups.php?token=…`, and v2 addresses a public group by slug instead;
 * turning old tokens into v2 share links is a separate feature (legacy
 * URL redirects), and inventing share links here would create objects
 * nobody asked for that expire on nothing.
 *
 * `created_by` is dropped for the same reason it is dropped everywhere
 * else in v1: it holds a *username string*, not a foreign key, so
 * renaming a user silently orphans it and there is nothing reliable to
 * resolve it against.
 */
final class GroupsPhase extends TablePhase
{
    private ?SlugReserver $slugs = null;

    public function key(): string
    {
        return 'groups';
    }

    public function label(): string
    {
        return 'Groups';
    }

    protected function table(): string
    {
        return V1Tables::GROUPS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $slugs = $this->slugs ??= SlugReserver::seededFrom(HostTables::GROUPS, 'group');
        $now = now();
        $tokens = 0;

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];
            $name = LegacyText::line((string) ($row['name'] ?? '')) ?: 'Group '.$sourceId;

            $id = (int) DB::table(HostTables::GROUPS)->insertGetId([
                'name' => $name,
                'slug' => $slugs->reserve($name),
                'description' => LegacyText::decode($row['description'] ?? null),
                'public' => (int) ($row['public'] ?? 0) === 1,
                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? $now,
                'updated_at' => $now,
            ]);

            if (($row['public_token'] ?? null) !== null && $row['public_token'] !== '') {
                $tokens++;
            }

            $context->idMap->record(MigrationIdMap::ENTITY_GROUP, $sourceId, $id);
            $context->count($this->key(), 'imported');
        }

        if ($tokens > 0) {
            $context->count($this->key(), 'public_tokens_not_carried', $tokens);
        }
    }
}
