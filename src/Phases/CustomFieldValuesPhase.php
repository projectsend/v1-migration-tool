<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;

/**
 * What each client answered for each custom field.
 */
final class CustomFieldValuesPhase extends TablePhase
{
    public function key(): string
    {
        return 'custom_field_values';
    }

    public function label(): string
    {
        return 'Client custom field values';
    }

    protected function table(): string
    {
        return V1Tables::CUSTOM_FIELD_VALUES;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_CUSTOM_FIELD);
        $context->idMap->preload(MigrationIdMap::ENTITY_USER);

        $now = now();
        $insert = [];

        foreach ($rows as $row) {
            $fieldId = $context->idMap->lookup(MigrationIdMap::ENTITY_CUSTOM_FIELD, (int) $row['field_id']);
            $userId = $context->idMap->lookup(MigrationIdMap::ENTITY_USER, (int) $row['user_id']);

            if ($fieldId === null || $userId === null) {
                $context->skipped($this->key(), 'the field or the account was not imported');

                continue;
            }

            $insert[] = [
                'client_custom_field_id' => $fieldId,
                'user_id' => $userId,
                'value' => LegacyText::decode($row['field_value'] ?? null),
                'created_at' => $row['created_date'] ?? $now,
                'updated_at' => $row['updated_date'] ?? $now,
            ];
        }

        foreach (array_chunk($insert, $context->insertChunk) as $chunk) {
            DB::table(HostTables::CLIENT_CUSTOM_FIELD_VALUES)->insertOrIgnore($chunk);
        }

        $context->count($this->key(), 'imported', count($insert));
    }
}
