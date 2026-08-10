<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Transform\OptionMap;

/**
 * The settings that could not be written until everything had ids, and
 * the last cache flush.
 *
 * Only one setting genuinely needs this: `clients_auto_group` holds a
 * group id, and groups get their v2 ids five phases after settings run.
 * Writing it early would point new clients at whatever group happened to
 * hold that id in v2 — which, on a fresh install, is none of them.
 *
 * The cache flush is not belt-and-braces either. The host caches every
 * setting under one key with `rememberForever`, and an import runs in a
 * queue worker, not the web process. Without this the site keeps serving
 * pre-migration settings until something else happens to invalidate them
 * — and `rememberForever` means that might be never.
 */
final class FinalisePhase implements Phase
{
    private const CACHE_KEY = 'platform.settings';

    public function key(): string
    {
        return 'finalise';
    }

    public function label(): string
    {
        return 'Finishing up';
    }

    public function total(MigrationContext $context): int
    {
        return 1;
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($cursor > 0) {
            return null;
        }

        $context->idMap->preload(MigrationIdMap::ENTITY_GROUP);

        $autoGroup = OptionMap::deferredAutoGroup($context->source->options());

        if ($autoGroup !== null) {
            $target = $context->idMap->lookup(MigrationIdMap::ENTITY_GROUP, $autoGroup);

            if ($target !== null) {
                DB::table(HostTables::SETTINGS)->updateOrInsert(
                    ['key' => 'clients_auto_group'],
                    [
                        'value' => json_encode($target, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $context->count($this->key(), 'auto_group_set');
            } else {
                $context->skipped($this->key(), 'the group new clients joined in v1 was not imported');
            }
        }

        Cache::forget(self::CACHE_KEY);

        $context->set($this->key(), 'completed_at', now()->toIso8601String());

        return 1;
    }
}
