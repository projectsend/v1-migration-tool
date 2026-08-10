<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Transform\OptionMap;

/**
 * v1's options into v2's settings.
 *
 * Small enough to do in one chunk — v1 has around 180 option rows and v2
 * has 43 settings, so there is nothing to page through.
 *
 * Two things this does that are easy to get wrong:
 *
 * **It writes JSON, because the host's settings.value column is JSON.**
 * The host normally goes through its Settings service, which type-checks
 * against a closed enum this package cannot import. Writing the table
 * directly means encoding correctly here — a raw `true` in that column
 * reads back as the string "1" and every boolean setting silently
 * inverts its meaning at the first comparison.
 *
 * **It flushes the host's settings cache afterwards.** The host caches
 * every setting under one key with `rememberForever`. Without the flush,
 * an import that ran through the queue leaves the web process serving
 * pre-migration settings until something else happens to invalidate
 * them — which, being `rememberForever`, might be never.
 */
final class SettingsPhase implements Phase
{
    private const CACHE_KEY = 'platform.settings';

    public function key(): string
    {
        return 'settings';
    }

    public function label(): string
    {
        return 'Settings';
    }

    public function total(MigrationContext $context): int
    {
        return count($context->source->options());
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($cursor > 0) {
            return null;
        }

        $converted = OptionMap::convert($context->source->options());
        $now = now();

        foreach ($converted['settings'] as $key => $value) {
            DB::table(HostTables::SETTINGS)->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $context->count($this->key(), 'settings_written', count($converted['settings']));
        $context->set($this->key(), 'not_carried', $converted['unmapped']);

        Cache::forget(self::CACHE_KEY);

        // Returning a cursor rather than null so the runner records that
        // this phase did work; the next call sees a non-zero cursor and
        // finishes.
        return 1;
    }
}
