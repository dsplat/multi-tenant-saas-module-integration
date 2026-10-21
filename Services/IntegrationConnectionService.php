<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 集成连接服务（框架标准件，BL-163 Stage B）
 *
 * 只管理连接配置与凭据，不做任何外部调用；连通性检查由调用方完成，
 * 结果用 `markChecked()` 回写。所有方法在显式租户上下文内执行。
 */
class IntegrationConnectionService
{
    /**
     * 新建连接；同一租户内 name 唯一。
     *
     * @param  array{base_url?: string|null, auth_type?: string, credentials?: array<string, mixed>|null, config?: array<string, mixed>|null, status?: string}  $attributes
     */
    public function create(int $tenantId, string $name, string $provider, array $attributes = []): IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationConnection::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'provider' => $provider,
            'base_url' => $attributes['base_url'] ?? null,
            'auth_type' => $attributes['auth_type'] ?? IntegrationConnection::AUTH_NONE,
            'credentials' => $attributes['credentials'] ?? null,
            'config' => $attributes['config'] ?? null,
            'status' => $attributes['status'] ?? IntegrationConnection::STATUS_ACTIVE,
        ]));
    }

    /**
     * 更新连接；provider 是身份维度，创建后不可改（游标/映射都挂在它上面）。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $tenantId, int $connectionId, array $attributes): IntegrationConnection
    {
        unset($attributes['connection_id'], $attributes['tenant_id'], $attributes['provider']);

        return TenantContext::runInTenant($tenantId, function () use ($tenantId, $connectionId, $attributes) {
            $connection = IntegrationConnection::where('tenant_id', $tenantId)->findOrFail($connectionId);
            $connection->fill($attributes)->save();

            return $connection->refresh();
        });
    }

    public function find(int $tenantId, int $connectionId): ?IntegrationConnection
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationConnection::where('tenant_id', $tenantId)
            ->find($connectionId));
    }

    /**
     * @return array<int, IntegrationConnection>
     */
    public function forProvider(int $tenantId, string $provider): array
    {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationConnection::where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->orderBy('connection_id')
            ->get()
            ->all());
    }

    /**
     * 回写最近一次连通性检查结果（错误为 null 表示检查通过）。
     */
    public function markChecked(int $tenantId, int $connectionId, ?string $error = null): void
    {
        TenantContext::runInTenant($tenantId, fn () => IntegrationConnection::where('tenant_id', $tenantId)
            ->whereKey($connectionId)
            ->update([
                'last_checked_at' => now(),
                'last_check_error' => $error === null ? null : mb_substr($error, 0, 1000),
            ]));
    }
}
