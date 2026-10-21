<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Illuminate\Database\QueryException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationInboxEvent;

/**
 * 收件箱服务（框架标准件，BL-163 Stage B）
 *
 * 外部事件先落库，消费方再按状态推进；`(connection_id, external_event_id)`
 * 唯一键保证重复投递幂等。所有方法在显式租户上下文内执行。
 */
class IntegrationInboxService
{
    /**
     * 记录外部事件；同一外部事件重复投递时返回首次落库的那条。
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        int $tenantId,
        int $connectionId,
        string $externalEventId,
        string $eventType,
        ?array $payload = null
    ): IntegrationInboxEvent {
        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $externalEventId, $eventType, $payload) {
            $find = fn () => IntegrationInboxEvent::where('tenant_id', $tenantId)
                ->where('connection_id', $connectionId)
                ->where('external_event_id', $externalEventId)
                ->first();

            if (($existing = $find()) !== null) {
                return $existing;
            }

            try {
                return IntegrationInboxEvent::create([
                    'tenant_id' => $tenantId,
                    'connection_id' => $connectionId,
                    'external_event_id' => $externalEventId,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => IntegrationInboxEvent::STATUS_PENDING,
                    'attempts' => 0,
                    'received_at' => now(),
                ]);
            } catch (QueryException $e) {
                // 并发投递：唯一键冲突后以先落库的记录为准
                return $find() ?? throw $e;
            }
        });
    }

    /**
     * 待消费事件（按接收时间 + 全局 ID 稳定排序）。
     *
     * @return array<int, IntegrationInboxEvent>
     */
    public function pending(int $tenantId, int $connectionId, int $limit = 100): array
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationInboxEvent::where('tenant_id', $tenantId)
            ->where('connection_id', $connectionId)
            ->where('status', IntegrationInboxEvent::STATUS_PENDING)
            ->orderBy('received_at')
            ->orderBy('event_id')
            ->limit($limit)
            ->get()
            ->all());
    }

    public function markProcessed(int $tenantId, int $eventId): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $eventId) {
            $event = IntegrationInboxEvent::where('tenant_id', $tenantId)->findOrFail($eventId);
            $event->status = IntegrationInboxEvent::STATUS_PROCESSED;
            $event->processed_at = now();
            $event->last_error = null;
            $event->save();
        });
    }

    public function markFailed(int $tenantId, int $eventId, string $error): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $eventId, $error) {
            $event = IntegrationInboxEvent::where('tenant_id', $tenantId)->findOrFail($eventId);
            $event->status = IntegrationInboxEvent::STATUS_FAILED;
            $event->attempts++;
            $event->last_error = mb_substr($error, 0, 1000);
            $event->save();
        });
    }
}
