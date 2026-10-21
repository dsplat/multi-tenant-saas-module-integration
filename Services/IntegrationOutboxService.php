<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationOutboxMessage;

/**
 * 发件箱服务（框架标准件，BL-163 Stage B）
 *
 * 对外写操作先入队再投递；`(connection_id, idempotency_key)` 唯一键保证重复
 * 入队幂等。投递失败按指数退避重试，超过上限转 `dead` 等人工处理。
 * 所有方法在显式租户上下文内执行。
 */
class IntegrationOutboxService
{
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
                    'available_at' => $availableAt ?? now(),
                ]);
            } catch (QueryException $e) {
                // 并发入队：唯一键冲突后以先入队的消息为准
                return $find() ?? throw $e;
            }
        });
    }

    /**
     * 认领到期消息并置为 `sending`（行锁串行化并发投递者）。
     *
     * @return array<int, IntegrationOutboxMessage>
     */
    public function claim(int $tenantId, int $connectionId, int $limit = 50): array
    {
        return TenantContext::runInTenant($tenantId, fn () => DB::transaction(function () use ($tenantId, $connectionId, $limit) {
            $messages = IntegrationOutboxMessage::where('tenant_id', $tenantId)
                ->where('connection_id', $connectionId)
                ->whereIn('status', [IntegrationOutboxMessage::STATUS_PENDING, IntegrationOutboxMessage::STATUS_FAILED])
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('message_id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($messages->isEmpty()) {
                return [];
            }

            IntegrationOutboxMessage::whereIn('message_id', $messages->pluck('message_id'))
                ->update(['status' => IntegrationOutboxMessage::STATUS_SENDING]);

            return $messages->map(function (IntegrationOutboxMessage $message) {
                $message->status = IntegrationOutboxMessage::STATUS_SENDING;

                return $message;
            })->all();
        }));
    }

    public function markSent(int $tenantId, int $messageId, ?string $externalRef = null): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $messageId, $externalRef) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)->findOrFail($messageId);
            $message->status = IntegrationOutboxMessage::STATUS_SENT;
            $message->external_ref = $externalRef;
            $message->sent_at = now();
            $message->last_error = null;
            $message->save();
        });
    }

    /**
     * 记录一次投递失败：未达上限转 `failed` 并按指数退避安排下次投递，
     * 达到上限转 `dead` 停止重试。
     */
    public function markFailed(int $tenantId, int $messageId, string $error, int $maxAttempts = 10): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $messageId, $error, $maxAttempts) {
            $message = IntegrationOutboxMessage::where('tenant_id', $tenantId)->findOrFail($messageId);
            $message->attempts++;
            $message->last_error = mb_substr($error, 0, 1000);

            if ($message->attempts >= $maxAttempts) {
                $message->status = IntegrationOutboxMessage::STATUS_DEAD;
            } else {
                $message->status = IntegrationOutboxMessage::STATUS_FAILED;
                $message->available_at = now()->addSeconds($this->backoffSeconds($message->attempts));
            }

            $message->save();
        });
    }

    /**
     * 指数退避：1 次失败 60s，之后翻倍，封顶 1 小时。
     */
    private function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 60 * (2 ** max(0, $attempts - 1)));
    }
}
