<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property string $entity
 * @property int $source_id
 * @property int|null $target_id
 * @property string $status
 * @property string|null $note
 */
class MigrationIdMap extends Model
{
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const ENTITY_ROLE = 'role';

    public const ENTITY_USER = 'user';

    public const ENTITY_GROUP = 'group';

    public const ENTITY_CATEGORY = 'category';

    public const ENTITY_FOLDER = 'folder';

    public const ENTITY_FILE = 'file';

    public const ENTITY_CUSTOM_FIELD = 'custom_field';

    protected $table = 'v1_migration_id_map';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'target_id' => 'integer',
        ];
    }
}
