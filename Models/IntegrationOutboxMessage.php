<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use DateTimeInterface;
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
        'claim_token', 'claim_count', 'claimed_at', 'lease_until',
    ];

    /**
     * claim_token 只作 fencing，不对 API/序列化暴露（审计日志同样不记录 token）。
     *
     * @var list<string>
     */
    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
            'lease_until' => 'datetime',
            'claim_count' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * 当前是否仍有未到期的 sending 租约。
     */
    public function hasActiveLease(?DateTimeInterface $now = null): bool
    {
        return $this->status === self::STATUS_SENDING
            && $this->lease_until !== null
            && $this->lease_until->greaterThan($now ?? now());
    }

    /**
     * sending 且租约已到期（可被回收）；无租约的历史 sending 行不算到期（需先对账）。
     */
    public function isLeaseExpired(?DateTimeInterface $now = null): bool
    {
        return $this->status === self::STATUS_SENDING
            && $this->lease_until !== null
            && $this->lease_until->lessThanOrEqualTo($now ?? now());
    }
}
