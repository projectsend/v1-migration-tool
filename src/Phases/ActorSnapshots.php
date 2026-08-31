<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;

/**
 * v2 user id => the actor facts an activity row snapshots: their type
 * ('staff' or 'client') and their display name.
 *
 * v2's activity log copies both onto the row rather than joining for
 * them, so an entry still reads correctly after the account is gone —
 * `actor_type` is how it keeps the distinction v1 encoded in separate
 * action codes (7 for a staff download, 8 for a client's), and
 * `actor_name` is what the downloads screen prints and filters on. A row
 * with a null `actor_name` renders as "(deleted account)" for an account
 * that is sitting right there, so this is not decoration.
 *
 * Something has to supply both, and the obvious options are wrong at
 * scale: joining per row is a query per activity entry, and preloading
 * every account is fine at a few thousand and not at a few hundred
 * thousand.
 *
 * So it fills in as it goes. Activity is dominated by a small number of
 * busy accounts, so the cache converges within the first chunks and the
 * queries stop. Both columns come from one query because they are wanted
 * together and the row is already being read.
 */
final class ActorSnapshots
{
    /** @var array<int, array{type: string|null, name: string|null}> */
    private array $actors = [];

    /**
     * @param  list<int|null>  $ids
     */
    public function warm(array $ids): void
    {
        $missing = [];

        foreach ($ids as $id) {
            if ($id !== null && $id > 0 && ! array_key_exists($id, $this->actors)) {
                $missing[$id] = true;
            }
        }

        if ($missing === []) {
            return;
        }

        $rows = DB::table(HostTables::USERS)
            ->whereIn('id', array_keys($missing))
            ->get(['id', 'type', 'name'])
            ->keyBy('id');

        foreach (array_keys($missing) as $id) {
            $row = $rows->get($id);

            $this->actors[$id] = [
                // An id that resolves to no row is cached as nulls rather
                // than left absent, so a v1 log pointing at an account
                // that was never imported is asked about once, not once
                // per row that mentions it.
                'type' => $row === null ? null : (string) $row->type,
                'name' => $row === null ? null : (string) $row->name,
            ];
        }
    }

    public function typeFor(?int $id): ?string
    {
        return $this->snapshot($id)['type'];
    }

    public function nameFor(?int $id): ?string
    {
        return $this->snapshot($id)['name'];
    }

    /**
     * @return array{type: string|null, name: string|null}
     */
    private function snapshot(?int $id): array
    {
        if ($id === null || $id <= 0) {
            return ['type' => null, 'name' => null];
        }

        return $this->actors[$id] ?? ['type' => null, 'name' => null];
    }
}
