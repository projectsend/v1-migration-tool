<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $status
 * @property string $mode
 * @property array<string, mixed> $source
 * @property string|null $phase
 * @property int $processed
 * @property int|null $total
 * @property array<string, mixed> $options
 * @property array<string, mixed>|null $report
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class MigrationRun extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Reading the source to decide whether the run may start. */
    public const STATUS_CHECKING = 'checking';

    /** Something would be lost. No flag overrides this. */
    public const STATUS_BLOCKED = 'blocked';

    /** Waiting for a human to confirm they have read what v2 cannot take. */
    public const STATUS_NEEDS_ACKNOWLEDGEMENT = 'needs_acknowledgement';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const MODE_DIRECT = 'direct';

    public const MODE_BUNDLE = 'bundle';

    protected $table = 'v1_migration_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => 'array',
            'options' => 'array',
            'report' => 'array',
            'processed' => 'integer',
            'total' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MigrationIdMap, $this>
     */
    public function idMap(): HasMany
    {
        return $this->hasMany(MigrationIdMap::class, 'run_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * Merge a fragment into the report without clobbering what earlier
     * phases wrote. Phases each own a top-level key, so a later phase
     * appending its counts must not have to know what came before it.
     *
     * @param  array<string, mixed>  $fragment
     */
    public function mergeReport(array $fragment): void
    {
        $this->report = array_replace_recursive($this->report ?? [], $fragment);
        $this->save();
    }
}
