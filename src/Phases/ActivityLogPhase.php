<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\ActionMap;
use ProjectSend\V1Migration\Transform\LegacyText;

/**
 * v1's activity log into v2's.
 *
 * The biggest table in the install, and the one with the most ways to be
 * wrong.
 *
 * **Unmapped codes are dropped, never approximated.** v2's `action`
 * column casts to a closed PHP enum, so an invented value throws when
 * the row is *read* — and the log is read a page at a time, so one bad
 * row takes out the screen for the fifty good ones beside it. Each
 * dropped code is counted with its reason in the report.
 *
 * **v1's `affected_file` and `affected_account` are unconstrained
 * integers.** There is no foreign key, and they routinely point at rows
 * that no longer exist — most obviously for "a file has been deleted",
 * where the file is gone by definition. So a subject that cannot be
 * resolved becomes no subject at all, and the row keeps its snapshotted
 * name, which is exactly what v2's `subject_name` column is for.
 *
 * **The actor's name is snapshotted too.** v1 stores `owner_user`, a
 * username string, beside `owner_id`. v2 has no username, but it does
 * keep an actor name so entries survive the account being deleted — so
 * the string goes there rather than being thrown away.
 */
final class ActivityLogPhase extends TablePhase
{
    private ActorTypes $actors;

    public function __construct()
    {
        $this->actors = new ActorTypes;
    }

    public function key(): string
    {
        return 'activity_log';
    }

    public function label(): string
    {
        return 'Activity history';
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
        return V1Tables::ACTIONS_LOG;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $fileIds = $context->idMap->lookupMany(
            MigrationIdMap::ENTITY_FILE,
            array_map(static fn (array $row): int => (int) ($row['affected_file'] ?? 0), $rows),
        );

        $actorIds = [];
        foreach ($rows as $row) {
            $actorIds[] = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) ($row['owner_id'] ?? 0));
        }
        $this->actors->warm($actorIds);

        $insert = [];

        foreach ($rows as $index => $row) {
            $code = (int) $row['action'];
            $action = ActionMap::for($code);

            if ($action === null) {
                $context->skipped($this->key(), ActionMap::dropReason($code));

                continue;
            }

            [$subjectType, $subjectId, $subjectName] = $this->subject($context, $row, $code, $fileIds);
            $actorId = $actorIds[$index];

            $insert[] = [
                'actor_id' => $actorId,
                'actor_name' => LegacyText::line($row['owner_user'] ?? null),
                'actor_type' => $this->actors->for($actorId),
                'origin' => ActionMap::origin($code),
                'ip_address' => null,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'context' => $this->context($row['details'] ?? null),
                'created_at' => $row['timestamp'] ?? now(),
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::ACTIVITY_LOG)->insert($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $fileIds
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function subject(MigrationContext $context, array $row, int $code, array $fileIds): array
    {
        if (ActionMap::subjectIsFile($code)) {
            $sourceId = (int) ($row['affected_file'] ?? 0);

            return [
                HostTables::MORPH_FILE,
                $fileIds[$sourceId] ?? null,
                LegacyText::line($row['affected_file_name'] ?? null),
            ];
        }

        if (ActionMap::subjectIsAccount($code)) {
            $sourceId = (int) ($row['affected_account'] ?? 0);

            return [
                HostTables::MORPH_USER,
                $context->idMap->lookup(MigrationIdMap::ENTITY_USER, $sourceId > 0 ? $sourceId : null),
                LegacyText::line($row['affected_account_name'] ?? null),
            ];
        }

        return [null, null, null];
    }

    /**
     * v1's `details` is nullable text that holds JSON for some codes and
     * a bare string for others. v2's column is JSON, so a non-JSON value
     * is wrapped rather than dropped — and rather than written raw,
     * which would make the column unreadable.
     */
    private function context(mixed $details): ?string
    {
        $details = trim((string) ($details ?? ''));

        if ($details === '') {
            return null;
        }

        $decoded = json_decode($details, true);

        return json_encode(
            is_array($decoded) ? $decoded : ['details' => LegacyText::decode($details)],
            JSON_THROW_ON_ERROR,
        );
    }
}
