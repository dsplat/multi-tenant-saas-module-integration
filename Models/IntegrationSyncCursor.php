<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 集成同步游标（连接 + 资源的增量拉取起点）
 *
 * 框架标准件（BL-163 Stage B）。
 */
class IntegrationSyncCursor extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    protected $table = 'integration_sync_cursors';

    protected $primaryKey = 'cursor_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'resource',
        'cursor_value', 'cursor_meta', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor_meta' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
