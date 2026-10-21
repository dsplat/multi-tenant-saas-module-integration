<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 集成收件箱事件（外部推送/拉取事件先落库再消费）
 *
 * 框架标准件（BL-163 Stage B）。
 */
class IntegrationInboxEvent extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    protected $table = 'integration_inbox_events';

    protected $primaryKey = 'event_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'external_event_id', 'event_type',
        'payload', 'status', 'attempts', 'last_error', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
