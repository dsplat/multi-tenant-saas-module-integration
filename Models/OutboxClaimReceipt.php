<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Models;

/**
 * Outbox ACK / 失败回执（BL-180 P3）
 *
 * 语义 reason 与 Playbook §4 对齐：
 * - acked：本次认领结果已成功写回；
 * - retry_scheduled：失败已记录，按退避等待下一次认领；
 * - dead：达到 attempts 上限，停止自动重试，保留原因待授权重试；
 * - stale_claim：租户/记录/sending 状态/claim token 任一不匹配，未修改任何新认领；
 * - not_found：记录不存在（含跨租户访问），不泄露其它租户记录。
 */
final class OutboxClaimReceipt
{
    public const STATUS_ACKED = 'acked';

    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const STATUS_DEAD = 'dead';

    public const STATUS_STALE_CLAIM = 'stale_claim';

    public const STATUS_NOT_FOUND = 'not_found';

    public const REASON_STALE_CLAIM = 'stale_claim';

    public function __construct(
        public readonly int $messageId,
        public readonly string $status,
        public readonly int $attempts = 0,
        public readonly ?string $reason = null,
    ) {}

    public static function acked(int $messageId): self
    {
        return new self($messageId, self::STATUS_ACKED);
    }

    public static function retryScheduled(int $messageId, int $attempts): self
    {
        return new self($messageId, self::STATUS_RETRY_SCHEDULED, $attempts);
    }

    public static function dead(int $messageId, int $attempts, ?string $reason = null): self
    {
        return new self($messageId, self::STATUS_DEAD, $attempts, $reason);
    }

    public static function staleClaim(int $messageId, int $attempts = 0): self
    {
        return new self($messageId, self::STATUS_STALE_CLAIM, $attempts, self::REASON_STALE_CLAIM);
    }

    public static function notFound(int $messageId): self
    {
        return new self($messageId, self::STATUS_NOT_FOUND, 0, self::STATUS_NOT_FOUND);
    }

    public function isAcked(): bool
    {
        return $this->status === self::STATUS_ACKED;
    }

    public function isStaleClaim(): bool
    {
        return $this->status === self::STATUS_STALE_CLAIM;
    }

    public function isDead(): bool
    {
        return $this->status === self::STATUS_DEAD;
    }

    /**
     * 结果是否已落库（acked/retry_scheduled/dead 均写入了本次认领的结果）。
     */
    public function wasRecorded(): bool
    {
        return in_array($this->status, [self::STATUS_ACKED, self::STATUS_RETRY_SCHEDULED, self::STATUS_DEAD], true);
    }
}
