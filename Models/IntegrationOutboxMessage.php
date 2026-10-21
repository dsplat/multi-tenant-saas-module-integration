<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 集成发件箱消息（对外写操作先落库再投递）
 *
 * 框架标准件（BL-163 Stage B）。
 */
class IntegrationOutboxMessage extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    protected $table = 'integration_outbox_messages';

    protected $primaryKey = 'message_id';

    protected $fillable = [
        'tenant_id', 'connection_id', 'idempotency_key', 'action', 'payload',
        'status', 'attempts', 'available_at', 'last_error', 'external_ref', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
