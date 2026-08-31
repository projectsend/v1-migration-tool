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
 * ### This is the only source of download history
 *
 * v1 records one download **twice**: `record_new_download()` writes a
 * `tbl_downloads` row, and the same request then logs actions_log code
 * 7/8 (or 37 when anonymous). Importing both put two v2 rows on the wire
 * for one real download and doubled every download count in the product.
 * So ActionMap drops 7/8/37 and this phase owns them outright — see the
 * reasoning there.
 *
 * `tbl_downloads` wins that choice because it is the ledger v1's own
 * numbers come from (`manage-files.php`'s `download_count`, the limit
 * checks in `functions.php`), so reading it alone reproduces exactly
 * what the customer saw in v1. It also carries the IP and the anonymous
 * flag, which the log row does not.
 *
 * What it does *not* carry is the two name snapshots, and those are not
 * cosmetic: v2 renders a null `actor_name` as "(deleted account)" for an
 * account that still exists, and the downloads screen filters on
 * `subject_name` and `actor_name` rather than joining, so rows without
 * them are invisible to both search boxes. v1 has no names in this
 * table, so they are read from the rows this migration has already
 * written — which is also what v2 itself would snapshot for a download
 * happening now.
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
    private ActorSnapshots $actors;

    public function __construct()
    {
        $this->actors = new ActorSnapshots;
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

        $fileNames = $this->fileNames($fileIds);

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
                'actor_name' => $this->actors->nameFor($actorId),
                'actor_type' => $this->actors->typeFor($actorId),
                'origin' => $anonymous ? 'public' : 'ui',
                'ip_address' => $this->ip($row['remote_ip'] ?? null),
                'action' => $anonymous ? 'public_file.downloaded' : 'file.downloaded',
                'subject_type' => HostTables::MORPH_FILE,
                'subject_id' => $fileId,
                'subject_name' => $fileNames[$fileId] ?? null,
                'context' => null,
                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? now(),
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::ACTIVITY_LOG)->insert($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }

    /**
     * v2 file id => name, for the ids in this chunk only.
     *
     * Deliberately not a cache that grows across chunks the way
     * ActorSnapshots is. Accounts are few and busy, so caching them
     * converges; files are many and a download history walks most of
     * them, so the same cache would end up holding a string per file in
     * the install with nothing ever evicting it.
     *
     * @param  array<int, int>  $fileIds  v1 id => v2 id
     * @return array<int, string>  v2 id => name
     */
    private function fileNames(array $fileIds): array
    {
        $ids = array_values(array_unique(array_filter($fileIds)));

        if ($ids === []) {
            return [];
        }

        return DB::table(HostTables::FILES)
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
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
