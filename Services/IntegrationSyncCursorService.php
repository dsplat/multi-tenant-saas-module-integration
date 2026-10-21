<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationSyncCursor;

/**
 * 同步游标服务（框架标准件，BL-163 Stage B）
 *
 * 一个连接 + 一个资源一条游标；只在同步成功推进后调用 `advance()`。
 * 所有方法在显式租户上下文内执行。
 */
class IntegrationSyncCursorService
{
    public function get(int $tenantId, int $connectionId, string $resource): ?IntegrationSyncCursor
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationSyncCursor::where('tenant_id', $tenantId)
            ->where('connection_id', $connectionId)
            ->where('resource', $resource)
            ->first());
    }

    /**
     * 推进游标（不存在则创建）。
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function advance(int $tenantId, int $connectionId, string $resource, ?string $value, ?array $meta = null): IntegrationSyncCursor
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationSyncCursor::updateOrCreate(
            [
                'connection_id' => $connectionId,
                'resource' => $resource,
            ],
            [
                'tenant_id' => $tenantId,
                'cursor_value' => $value,
                'cursor_meta' => $meta,
                'last_synced_at' => now(),
            ]
        ));
    }

    /**
     * 清空游标（下一次同步从头拉取）。
     */
    public function reset(int $tenantId, int $connectionId, string $resource): void
    {
        TenantContext::runInTenant($tenantId, fn () => IntegrationSyncCursor::where('tenant_id', $tenantId)
            ->where('connection_id', $connectionId)
            ->where('resource', $resource)
            ->update([
                'cursor_value' => null,
                'cursor_meta' => null,
                'last_synced_at' => null,
            ]));
    }
}
