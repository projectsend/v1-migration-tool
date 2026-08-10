<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;

/**
 * v2 user id => 'staff' or 'client', for stamping activity rows.
 *
 * v2's activity log snapshots the actor's type on the row, which is how
 * it keeps the distinction v1 encoded in separate action codes (7 for a
 * staff download, 8 for a client's). Something has to supply it, and the
 * obvious options are both wrong at scale: joining per row is a query
 * per activity entry, and preloading every account is fine at a few
 * thousand and not at a few hundred thousand.
 *
 * So it fills in as it goes. Activity is dominated by a small number of
 * busy accounts, so the cache converges within the first chunks and the
 * queries stop.
 */
final class ActorTypes
{
    /** @var array<int, string|null> */
    private array $types = [];

    /**
     * @param  list<int|null>  $ids
     */
    public function warm(array $ids): void
    {
        $missing = [];

        foreach ($ids as $id) {
            if ($id !== null && $id > 0 && ! array_key_exists($id, $this->types)) {
                $missing[$id] = true;
            }
        }

        if ($missing === []) {
            return;
        }

        $rows = DB::table(HostTables::USERS)
            ->whereIn('id', array_keys($missing))
            ->pluck('type', 'id');

        foreach (array_keys($missing) as $id) {
            $this->types[$id] = isset($rows[$id]) ? (string) $rows[$id] : null;
        }
    }

    public function for(?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->types[$id] ?? null;
    }
}
