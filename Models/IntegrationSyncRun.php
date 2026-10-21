<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 集成同步运行记录（一次拉取/推送的结果与统计）
 *
 * 框架标准件（BL-163 Stage B）。
 */
class IntegrationSyncRun extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_WEBHOOK = 'webhook';

    protected $table = 'integration_sync_runs';

    protected $primaryKey = 'run_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'resource', 'direction', 'trigger_type',
        'status', 'started_at', 'finished_at', 'stats', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
