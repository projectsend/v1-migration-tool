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
 * Extra fields on client accounts.
 *
 * Two v1 concepts have no v2 counterpart and are handled rather than
 * approximated:
 *
 * **`applies_to = 'user'`.** v1 could attach custom fields to staff
 * accounts; v2's are client-only. Those definitions are skipped and
 * counted — there is nowhere to put them.
 *
 * **`is_visible_to_client`.** v1 has visible/hidden. v2 has hidden,
 * editable, and editable-once — there is no "the client can see this but
 * not change it". Everything imports **hidden**, because the alternative
 * is granting clients the ability to edit fields that were read-only to
 * them, and a migration must never hand out more access than the install
 * it came from. The count is reported so an administrator can open the
 * handful that should be editable.
 */
final class CustomFieldsPhase extends TablePhase
{
    /** v1 field_type => v2 ClientCustomFieldType. Identical vocabularies. */
    private const TYPES = ['text', 'textarea', 'select', 'checkbox'];

    public function key(): string
    {
        return 'custom_fields';
    }

    public function label(): string
    {
        return 'Client custom fields';
    }

    protected function table(): string
    {
        return V1Tables::CUSTOM_FIELDS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $now = now();
        $wasVisible = 0;

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];
            $appliesTo = (string) ($row['applies_to'] ?? 'client');

            if ($appliesTo === 'user') {
                $context->idMap->skip(MigrationIdMap::ENTITY_CUSTOM_FIELD, $sourceId, 'applies to staff, not clients');
                $context->skipped($this->key(), 'field applies to staff accounts, which v2 has no custom fields for');

                continue;
            }

            $type = (string) ($row['field_type'] ?? 'text');

            if (! in_array($type, self::TYPES, true)) {
                $context->idMap->skip(MigrationIdMap::ENTITY_CUSTOM_FIELD, $sourceId, "unknown field type `{$type}`");
                $context->skipped($this->key(), "unknown v1 field type `{$type}`");

                continue;
            }

            if ((int) ($row['is_visible_to_client'] ?? 0) === 1) {
                $wasVisible++;
            }

            $id = (int) DB::table(HostTables::CLIENT_CUSTOM_FIELDS)->insertGetId([
                'name' => $this->uniqueName((string) ($row['field_name'] ?? 'field_'.$sourceId)),
                'label' => LegacyText::line((string) ($row['field_label'] ?? '')) ?: (string) $row['field_name'],
                'type' => $type,
                'options' => $this->options($row['field_options'] ?? null),
                'required' => (int) ($row['is_required'] ?? 0) === 1,
                'client_editability' => 'hidden',
                'client_contexts' => json_encode([], JSON_THROW_ON_ERROR),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'created_at' => $context->clock->toUtc($row['created_date'] ?? null) ?? $now,
                'updated_at' => $now,
            ]);

            $context->idMap->record(MigrationIdMap::ENTITY_CUSTOM_FIELD, $sourceId, $id);
            $context->count($this->key(), 'imported');
        }

        if ($wasVisible > 0) {
            $context->count($this->key(), 'visible_in_v1_imported_hidden', $wasVisible);
        }
    }

    /**
     * v1 stores select options as a newline- or comma-separated string;
     * v2 wants a JSON array.
     */
    private function options(mixed $raw): ?string
    {
        $raw = trim((string) ($raw ?? ''));

        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim(LegacyText::line($part) ?? ''),
            $parts,
        )));

        return $parts === [] ? null : json_encode($parts, JSON_THROW_ON_ERROR);
    }

    /**
     * v2's client_custom_fields.name is the machine key and is unique;
     * v1's field_name is not.
     */
    private function uniqueName(string $name): string
    {
        $base = $name !== '' ? $name : 'field';
        $candidate = $base;
        $suffix = 2;

        while (DB::table(HostTables::CLIENT_CUSTOM_FIELDS)->where('name', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
