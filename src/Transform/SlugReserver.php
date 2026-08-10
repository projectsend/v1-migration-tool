<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hands out unique slugs without asking the database each time.
 *
 * v2 requires a NOT NULL UNIQUE slug on files, folders and groups, and
 * derives it from the name. The host does this in `HasUniqueSlug`, whose
 * docblock names "the v1 importer" as one of its call sites — but its
 * `uniqueSlugFrom()` runs an `exists()` query per candidate, and a
 * collision runs one per attempt. On an install with 200,000 files, most
 * of them named things like "Final" and "Proof v2", that is not a
 * detail: it is the entire import, spent on SELECTs.
 *
 * So this reserves in memory instead. It is seeded once from whatever
 * the host already has (on a fresh install, nothing), and every slug it
 * issues is remembered. The rules match `HasUniqueSlug` exactly —
 * `Str::slug()`, a `-2`, `-3` … suffix on collision, a fallback when the
 * name slugs to nothing — because the host keeps using its own version
 * for everything created after the migration, and the two must not
 * disagree about what a slug looks like.
 *
 * The fallback matters more here than it does in the host. `Str::slug()`
 * returns an empty string for a name made entirely of characters it
 * drops — every CJK title, every name that is only punctuation — and v1
 * installs are full of both.
 */
final class SlugReserver
{
    /** @var array<string, true> */
    private array $taken = [];

    public function __construct(private readonly string $fallback) {}

    /**
     * Seed from a host table's existing slugs.
     *
     * A fresh install has none, which is the supported case — but this
     * is what makes the reserver correct rather than merely lucky, and
     * it costs one query.
     */
    public static function seededFrom(string $table, string $fallback, string $column = 'slug'): self
    {
        $reserver = new self($fallback);

        foreach (DB::table($table)->pluck($column) as $slug) {
            if (is_string($slug) && $slug !== '') {
                $reserver->taken[$slug] = true;
            }
        }

        return $reserver;
    }

    public function reserve(?string $name): string
    {
        $base = Str::slug((string) $name);

        if ($base === '') {
            $base = $this->fallback;
        }

        $slug = $base;
        $suffix = 2;

        while (isset($this->taken[$slug])) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        $this->taken[$slug] = true;

        return $slug;
    }

    /**
     * How many slugs have been handed out. Only used by tests and the
     * run report, but it is the cheapest way to prove the reserver was
     * actually consulted for every row rather than bypassed.
     */
    public function count(): int
    {
        return count($this->taken);
    }
}
