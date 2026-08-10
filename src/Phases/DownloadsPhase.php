<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;

/**
 * v1's downloads table into v2's activity log.
 *
 * v2 has no downloads table. A download *is* an activity row, and every
 * download count in the interface is a `withCount` over the log filtered
 * to three actions. So this is not an optional nicety: skip it and every
 * file in the imported install shows zero downloads forever, which reads
 * as data loss to the person looking at it.
 *
 * It is also the largest table in most installs after the activity log
 * itself — a hundred thousand rows in the mid-size fixture, millions on
 * a busy install — so nothing here is per-row. One `whereIn` resolves a
 * chunk's file ids, one insert writes the chunk.
 *
 * `anonymous` decides both the action and the origin: an anonymous
 * download of a public file did not come from a session, and v2's
 * download screens filter on exactly that.
 */
final class DownloadsPhase extends TablePhase
{
    private ActorTypes $actors;

    public function __construct()
    {
        $this->actors = new ActorTypes;
    }

    public function key(): string
    {
        return 'downloads';
    }

    public function label(): string
    {
        return 'Download history';
    }

    public function total(MigrationContext $context): int
    {
        return $context->option('history', 'full') === 'none'
            ? 0
            : parent::total($context);
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($context->option('history', 'full') === 'none') {
            return null;
        }

        return parent::chunk($context, $cursor);
    }

    protected function table(): string
    {
        return V1Tables::DOWNLOADS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $fileIds = $context->idMap->lookupMany(
            MigrationIdMap::ENTITY_FILE,
            array_map(static fn (array $row): int => (int) $row['file_id'], $rows),
        );

        $actorIds = [];
        foreach ($rows as $row) {
            $actorIds[] = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) ($row['user_id'] ?? 0));
        }
        $this->actors->warm($actorIds);

        $insert = [];

        foreach ($rows as $index => $row) {
            $fileId = $fileIds[(int) $row['file_id']] ?? null;

            if ($fileId === null) {
                $context->skipped($this->key(), 'the file was not imported');

                continue;
            }

            $anonymous = (int) ($row['anonymous'] ?? 0) === 1;
            $actorId = $actorIds[$index];

            $insert[] = [
                'actor_id' => $actorId,
                'actor_name' => null,
                'actor_type' => $this->actors->for($actorId),
                'origin' => $anonymous ? 'public' : 'ui',
                'ip_address' => $this->ip($row['remote_ip'] ?? null),
                'action' => $anonymous ? 'public_file.downloaded' : 'file.downloaded',
                'subject_type' => HostTables::MORPH_FILE,
                'subject_id' => $fileId,
                'subject_name' => null,
                'context' => null,
                'created_at' => $row['timestamp'] ?? now(),
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::ACTIVITY_LOG)->insert($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }

    /**
     * v1's remote_ip is varchar(100); v2's column is 45, which is the
     * longest an IPv6 address with an embedded IPv4 can be. Anything
     * longer was never an address.
     */
    private function ip(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' || strlen($value) > 45 ? null : $value;
    }
}
