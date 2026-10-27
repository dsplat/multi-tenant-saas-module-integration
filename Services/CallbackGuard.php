<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 回调/OAuth 事务防重放守卫（WP-H）。
 *
 * state/nonce 一次性消费（Cache::add 原子单次）+ 时间窗校验；
 * 时钟与随机源可注入（测试确定性，生产必须用密码学随机）。
 */
class CallbackGuard
{
    public const NONCE_TTL_SECONDS = 600;

    public const MAX_AGE_SECONDS = 300;

    public const FUTURE_SKEW_SECONDS = 60;

    public function __construct(
        private readonly ?Closure $clock = null,
        private readonly ?Closure $random = null,
    ) {}

    /**
     * 生成一次性 nonce（生产为密码学随机；测试可注入随机源）
     */
    public function newNonce(): string
    {
        if ($this->random !== null) {
            return (string) ($this->random)($this->now());
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * 原子消费 nonce：同一 nonce 第二次消费返回 false（重放拒绝）
     */
    public function consumeNonce(string $nonce, ?int $ttlSeconds = null): bool
    {
        if ($nonce === '') {
            return false;
        }

        return Cache::add('integration:callback:nonce:' . $nonce, 1, $ttlSeconds ?? self::NONCE_TTL_SECONDS);
    }

    /**
     * 时间窗校验：过旧或超前都拒绝
     */
    public function withinWindow(\DateTimeImmutable $issuedAt, ?int $maxAgeSeconds = null): bool
    {
        $now = $this->now();
        $age = $now->getTimestamp() - $issuedAt->getTimestamp();

        return $age <= ($maxAgeSeconds ?? self::MAX_AGE_SECONDS)
            && $age >= -self::FUTURE_SKEW_SECONDS;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock !== null
            ? ($this->clock)()
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
