<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationOutboxMessage;
use MultiTenantSaas\Modules\Integration\Models\OutboxClaimReceipt;

/**
 * 发件箱服务（框架标准件，BL-163 Stage B；租约/认领 BL-180 P3 / BL-185 R2）
 *
 * 对外写操作先入队再投递；`(connection_id, idempotency_key)` 唯一键保证重复
 * 入队幂等。投递失败按指数退避重试，超过上限转 `dead` 等人工处理。
 *
 * 认领契约（Playbook §3.3）：
 * - 每次认领生成新的 `claim_token` 与 `lease_until`，认领次数记入 `claim_count`；
 * - pending/failed 到期可认领；sending 租约到期可回收，且消耗一次 attempts；
 * - 租约到期回收后 attempts 达上限 → dead 并保留原因；dead 只能经授权重试；
 * - ACK/失败回写必须同时匹配租户、记录、sending 状态与 claim_token，旧 token
 *   返回 stale_claim 且不修改任何新认领；
 * - 到期判断一律取数据库时间（避免多 worker 进程时钟漂移），不依赖调用方时钟。
 *
 * 兼容桥：既有调用方 `markSent()/markFailed()` 不带 token 仍可用。服务在同一
 * 进程内记录本次认领签发的 token，无 token 回写时自动带上；不存在本进程 token
 * 且记录已有 `claim_token` 时按 stale_claim 拒绝（旧 worker 不能覆盖新结果）。
 * 无 token 的历史 sending 行（迁移前遗留、结果不可知）仍按旧语义处理，并由
 * `inspect()` 的 unconfirmed 列表暴露，需对账后再处置。新调用方应使用 `ack()/fail()`。
 */
class IntegrationOutboxService
{
    /** 单批认领上限：防止一次拉取过多导致长事务与投递挤兑 */
    public const MAX_CLAIM_BATCH = 200;

    /** 默认租约时长（秒） */
    public const DEFAULT_LEASE_SECONDS = 300;

    /** 租约时长下限（秒） */
    public const MIN_LEASE_SECONDS = 30;

    /** 租约时长上限（秒） */
    public const MAX_LEASE_SECONDS = 3600;

    /** 默认 attempts 上限（与既有 markFailed 默认值一致） */
    public const DEFAULT_MAX_ATTEMPTS = 10;

    /** 进程内 claim token 登记上限（防止长驻 worker 无限增长） */
    private const MAX_TRACKED_CLAIMS = 10_000;

    /** 最多保留的错误原因长度（与 last_error 列宽一致） */
    private const MAX_ERROR_LENGTH = 1000;

    /** @var array<int, string> 本进程签发的 claim token（message_id => token） */
    private static array $issuedClaimTokens = [];

    /**
     * 入队一条对外操作；同一幂等键重复调用返回首次入队的消息（参数不变）。
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function enqueue(
        int $tenantId,
        int $connectionId,
        string $idempotencyKey,
        string $action,
        ?array $payload = null,
        ?DateTimeInterface $availableAt = null
    ): IntegrationOutboxMessage {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $idempotencyKey, $action, $payload, $availableAt) {
            $find = fn () => IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->where('connection_id', $connectionId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (($existing = $find()) !== null) {
                return $existing;
            }

            try {
                return IntegrationOutboxMessage::create([
                    'tenant_id' => $tenantId,
                    'connection_id' => $connectionId,
                    'idempotency_key' => $idempotencyKey,
                    'action' => $action,
                    'payload' => $payload,
                    'status' => IntegrationOutboxMessage::STATUS_PENDING,
                    'attempts' => 0,
                    'claim_count' => 0,
                    'available_at' => $availableAt ?? now(),
                ]);
            } catch (QueryException $e) {
                // 并发入队：唯一键冲突后以先入队的消息为准
                return $find() ?? throw $e;
            }
        });
    }

    /**
     * 认领到期消息并生成新的 claim token / 租约（行锁串行化并发投递者）。
     *
     * - pending/failed 且 available_at 已到（数据库时间）→ 可认领；
     * - sending 且 lease_until 已到期（数据库时间）→ 回收，attempts+1；
     * - 回收后 attempts 达上限 → 直接转 dead，不返回给投递者；
     * - sending 但 lease_until 为 NULL（迁移前历史行）→ 不自动回收，先对账。
     *
     * @param  int  $limit  单批条数（1..MAX_CLAIM_BATCH，越界自动钳制）
     * @param  int  $leaseDuration  租约秒数（MIN_LEASE_SECONDS..MAX_LEASE_SECONDS）
     * @param  int|null  $maxAttempts  租约到期回收的 attempts 上限（默认 DEFAULT_MAX_ATTEMPTS）
     * @return array<int, IntegrationOutboxMessage>
     */
    public function claim(
        int $tenantId,
        int $connectionId,
        int $limit = 50,
        int $leaseDuration = self::DEFAULT_LEASE_SECONDS,
        ?int $maxAttempts = null
    ): array {
        $limit = max(1, min($limit, self::MAX_CLAIM_BATCH));
        $leaseDuration = max(self::MIN_LEASE_SECONDS, min($leaseDuration, self::MAX_LEASE_SECONDS));
        $maxAttempts = max(1, $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS);

        return TenantContext::runInTenant($tenantId, fn () => DB::transaction(function () use ($tenantId, $connectionId, $limit, $leaseDuration, $maxAttempts) {
            // 到期基准取数据库时间：多 worker 进程时钟漂移不影响租约判定。
            $leaseStart = $this->databaseNow();
            $leaseUntil = $leaseStart->copy()->addSeconds($leaseDuration);

            $messages = IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->where('connection_id', $connectionId)
                ->where(function ($query) use ($leaseStart) {
                    $query->where(function ($query) use ($leaseStart) {
                        $query->whereIn('status', [
                            IntegrationOutboxMessage::STATUS_PENDING,
                            IntegrationOutboxMessage::STATUS_FAILED,
                        ])->where('available_at', '<=', $leaseStart);
                    })->orWhere(function ($query) use ($leaseStart) {
                        $query->where('status', IntegrationOutboxMessage::STATUS_SENDING)
                            ->whereNotNull('lease_until')
                            ->where('lease_until', '<=', $leaseStart);
                    });
                })
                ->orderBy('available_at')
                ->orderBy('message_id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($messages->isEmpty()) {
                return [];
            }

            $claimed = [];

            foreach ($messages as $message) {
                $messageId = (int) $message->message_id;

                if ($message->status === IntegrationOutboxMessage::STATUS_SENDING) {
                    // 租约到期＝上一次投递结果不可知：消耗一次尝试，不能让崩溃无限重试。
                    $nextAttempts = (int) $message->attempts + 1;
                    $message->attempts = $nextAttempts;
                    $message->last_error = $this->leaseError($message->last_error);
                    $message->claim_count = (int) $message->claim_count + 1;

                    if ($nextAttempts >= $maxAttempts) {
                        $message->status = IntegrationOutboxMessage::STATUS_DEAD;
                        $message->lease_until = null;
                        $message->claimed_at = null;
                        $message->save();

                        self::forgetClaim($messageId);

                        continue;
                    }
                } else {
                    $message->claim_count = (int) $message->claim_count + 1;
                }

                $token = bin2hex(random_bytes(32));

                $message->status = IntegrationOutboxMessage::STATUS_SENDING;
                $message->claim_token = $token;
                $message->claimed_at = $leaseStart->copy();
                $message->lease_until = $leaseUntil->copy();
                $message->save();

                self::rememberClaim($messageId, $token);

                $claimed[] = $message;
            }

            return $claimed;
        }));
    }

    /**
     * 成功回执：同时匹配租户、记录、sending 状态与 claim token。
     *
     * 旧 token / 非 sending / token 缺失一律 stale_claim，且不写入任何字段。
     */
    public function ack(int $tenantId, int $messageId, string $claimToken, ?string $externalRef = null): OutboxClaimReceipt
    {
        return TenantContext::runInTenant($tenantId, fn () => DB::transaction(function () use ($tenantId, $messageId, $claimToken, $externalRef) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return OutboxClaimReceipt::notFound($messageId);
            }

            if ($message->status !== IntegrationOutboxMessage::STATUS_SENDING || ! $this->claimTokenMatches($message, $claimToken)) {
                return OutboxClaimReceipt::staleClaim($messageId, (int) $message->attempts);
            }

            $message->status = IntegrationOutboxMessage::STATUS_SENT;
            $message->external_ref = $externalRef;
            $message->sent_at = now();
            $message->last_error = null;
            $message->lease_until = null;
            $message->save();

            self::forgetClaim($messageId, $claimToken);

            return OutboxClaimReceipt::acked($messageId);
        }));
    }

    /**
     * 失败回执：同时匹配租户、记录、sending 状态与 claim token。
     *
     * 未达上限转 `failed` 并按指数退避安排下次投递；达到上限转 `dead` 并保留原因。
     * 旧 token 一律 stale_claim，且不增加新认领的 attempts。
     */
    public function fail(
        int $tenantId,
        int $messageId,
        string $claimToken,
        string $error,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): OutboxClaimReceipt {
        $maxAttempts = max(1, $maxAttempts);

        return TenantContext::runInTenant($tenantId, fn () => DB::transaction(function () use ($tenantId, $messageId, $claimToken, $error, $maxAttempts) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return OutboxClaimReceipt::notFound($messageId);
            }

            if ($message->status !== IntegrationOutboxMessage::STATUS_SENDING || ! $this->claimTokenMatches($message, $claimToken)) {
                return OutboxClaimReceipt::staleClaim($messageId, (int) $message->attempts);
            }

            $receipt = $this->applyFailure($message, $error, $maxAttempts);

            self::forgetClaim($messageId, $claimToken);

            return $receipt;
        }));
    }

    /**
     * 成功回写（兼容入口）。
     *
     * @param  string|null  $claimToken  新调用方应显式传入 claim() 返回的 token；
     *                                   省略时仅当本进程持有该消息的最新认领 token 才放行。
     */
    public function markSent(int $tenantId, int $messageId, ?string $externalRef = null, ?string $claimToken = null): void
    {
        $token = $claimToken ?? self::rememberedClaim($messageId);

        if ($token === null) {
            $this->legacyMarkSent($tenantId, $messageId, $externalRef);

            return;
        }

        $receipt = $this->ack($tenantId, $messageId, $token, $externalRef);

        if (! $receipt->isAcked()) {
            $this->logStaleLegacyCall('markSent', $messageId, $receipt);
        }
    }

    /**
     * 失败回写（兼容入口）。
     *
     * @param  string|null  $claimToken  新调用方应显式传入 claim() 返回的 token；
     *                                   省略时仅当本进程持有该消息的最新认领 token 才放行。
     */
    public function markFailed(
        int $tenantId,
        int $messageId,
        string $error,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?string $claimToken = null
    ): void {
        $token = $claimToken ?? self::rememberedClaim($messageId);

        if ($token === null) {
            $this->legacyMarkFailed($tenantId, $messageId, $error, $maxAttempts);

            return;
        }

        $receipt = $this->fail($tenantId, $messageId, $token, $error, $maxAttempts);

        if (! $receipt->wasRecorded()) {
            $this->logStaleLegacyCall('markFailed', $messageId, $receipt);
        }
    }

    /**
     * 只读巡检（dry-run / shadow）：不取锁、不写库。
     *
     * 输出四类待处置消息 ID：
     * - due：pending/failed 且到期，可立即认领；
     * - expired：sending 且租约已到期，可被回收；
     * - dead：已停止自动重试，等授权重试；
     * - unconfirmed：sending 且无租约（迁移前遗留），结果不可知，须先对账。
     *
     * @return array{due: list<int>, expired: list<int>, dead: list<int>, unconfirmed: list<int>}
     */
    public function inspect(int $tenantId, int $connectionId, int $limit = self::MAX_CLAIM_BATCH): array
    {
        $limit = max(1, min($limit, self::MAX_CLAIM_BATCH));

        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $limit) {
            $now = $this->databaseNow();

            $base = fn () => IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->where('connection_id', $connectionId);

            $ids = static fn ($query): array => $query
                ->orderBy('message_id')
                ->limit($limit)
                ->pluck('message_id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();

            return [
                'due' => $ids($base()->whereIn('status', [
                    IntegrationOutboxMessage::STATUS_PENDING,
                    IntegrationOutboxMessage::STATUS_FAILED,
                ])->where('available_at', '<=', $now)),
                'expired' => $ids($base()->where('status', IntegrationOutboxMessage::STATUS_SENDING)
                    ->whereNotNull('lease_until')
                    ->where('lease_until', '<=', $now)),
                'dead' => $ids($base()->where('status', IntegrationOutboxMessage::STATUS_DEAD)),
                'unconfirmed' => $ids($base()->where('status', IntegrationOutboxMessage::STATUS_SENDING)
                    ->whereNull('lease_until')),
            ];
        });
    }

    /**
     * 兼容：无本进程 token 时按旧语义回写。
     *
     * 已带 claim_token 的记录拒绝回写（旧 worker 的 tokenless ACK 不能覆盖新认领）；
     * 仅迁移前遗留的、从未带 token 的 sending 行保持旧行为。
     */
    private function legacyMarkSent(int $tenantId, int $messageId, ?string $externalRef): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $messageId, $externalRef) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)->findOrFail($messageId);

            if ($message->claim_token !== null) {
                Log::warning('integration.outbox.tokenless_ack_ignored', [
                    'operation' => 'markSent',
                    'message_id' => $messageId,
                    'reason' => OutboxClaimReceipt::REASON_STALE_CLAIM,
                ]);

                return;
            }

            $message->status = IntegrationOutboxMessage::STATUS_SENT;
            $message->external_ref = $externalRef;
            $message->sent_at = now();
            $message->last_error = null;
            $message->lease_until = null;
            $message->save();
        });
    }

    /**
     * 兼容：无本进程 token 时的失败回写；规则同 legacyMarkSent()。
     */
    private function legacyMarkFailed(int $tenantId, int $messageId, string $error, int $maxAttempts): void
    {
        $maxAttempts = max(1, $maxAttempts);

        TenantContext::runInTenant($tenantId, function () use ($tenantId, $messageId, $error, $maxAttempts) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)->findOrFail($messageId);

            if ($message->claim_token !== null) {
                Log::warning('integration.outbox.tokenless_ack_ignored', [
                    'operation' => 'markFailed',
                    'message_id' => $messageId,
                    'reason' => OutboxClaimReceipt::REASON_STALE_CLAIM,
                ]);

                return;
            }

            $this->applyFailure($message, $error, $maxAttempts);
        });
    }

    private function applyFailure(IntegrationOutboxMessage $message, string $error, int $maxAttempts): OutboxClaimReceipt
    {
        $message->attempts = (int) $message->attempts + 1;
        $message->last_error = mb_substr($error, 0, self::MAX_ERROR_LENGTH);
        $message->lease_until = null;

        if ($message->attempts >= $maxAttempts) {
            $message->status = IntegrationOutboxMessage::STATUS_DEAD;
            $message->save();

            return OutboxClaimReceipt::dead((int) $message->message_id, (int) $message->attempts, $message->last_error);
        }

        $message->status = IntegrationOutboxMessage::STATUS_FAILED;
        $message->available_at = now()->addSeconds($this->backoffSeconds((int) $message->attempts));
        $message->save();

        return OutboxClaimReceipt::retryScheduled((int) $message->message_id, (int) $message->attempts);
    }

    /**
     * 指数退避：1 次失败 60s，之后翻倍，封顶 1 小时。
     */
    private function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 60 * (2 ** max(0, $attempts - 1)));
    }

    /**
     * 取数据库当前时间并归一化到应用时区。
     *
     * 各驱动统一取 UTC 再转换，避免 MySQL session 时区与 SQLite UTC 语义差异：
     * sqlite CURRENT_TIMESTAMP 本身即 UTC；MySQL 用 UTC_TIMESTAMP()；PostgreSQL
     * 显式 AT TIME ZONE 'UTC'。未知驱动退回 CURRENT_TIMESTAMP（按 UTC 解析）。
     */
    private function databaseNow(): Carbon
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $sql = match ($driver) {
            'mysql', 'mariadb' => 'SELECT UTC_TIMESTAMP() AS current_timestamp',
            'pgsql' => "SELECT (NOW() AT TIME ZONE 'UTC') AS current_timestamp",
            default => 'SELECT CURRENT_TIMESTAMP AS current_timestamp',
        };

        $row = (array) $connection->selectOne($sql);
        $value = (string) reset($row);

        $timezone = (string) config('app.timezone', 'UTC');
        $timezone = $timezone === '' ? 'UTC' : $timezone;

        return Carbon::parse($value, 'UTC')->setTimezone($timezone);
    }

    private function claimTokenMatches(IntegrationOutboxMessage $message, string $claimToken): bool
    {
        $expected = $message->claim_token;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $claimToken);
    }

    /**
     * 租约到期原因：保留最近一次失败原因，并标记 lease_expired。
     */
    private function leaseError(?string $previous): string
    {
        $previous = trim((string) $previous);

        if ($previous === '') {
            return 'lease_expired';
        }

        if (str_contains($previous, 'lease_expired')) {
            return mb_substr($previous, 0, self::MAX_ERROR_LENGTH);
        }

        return mb_substr('lease_expired; ' . $previous, 0, self::MAX_ERROR_LENGTH);
    }

    private function logStaleLegacyCall(string $operation, int $messageId, OutboxClaimReceipt $receipt): void
    {
        Log::warning('integration.outbox.stale_claim', [
            'operation' => $operation,
            'message_id' => $messageId,
            'status' => $receipt->status,
            'reason' => $receipt->reason,
        ]);
    }

    private static function rememberClaim(int $messageId, string $token): void
    {
        self::$issuedClaimTokens[$messageId] = $token;

        if (count(self::$issuedClaimTokens) > self::MAX_TRACKED_CLAIMS) {
            self::$issuedClaimTokens = array_slice(
                self::$issuedClaimTokens,
                intdiv(self::MAX_TRACKED_CLAIMS, 2),
                null,
                true,
            );
        }
    }

    private static function rememberedClaim(int $messageId): ?string
    {
        return self::$issuedClaimTokens[$messageId] ?? null;
    }

    private static function forgetClaim(int $messageId, ?string $token = null): void
    {
        if ($token !== null && (self::$issuedClaimTokens[$messageId] ?? null) !== $token) {
            return;
        }

        unset(self::$issuedClaimTokens[$messageId]);
    }
}
