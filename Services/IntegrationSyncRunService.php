<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\IntegrationSyncRun;

/**
 * 同步运行记录服务（框架标准件，BL-163 Stage B）
 *
 * 一次拉取/推送一条记录；游标推进与业务写入由调用方在 `finish()` 之前完成，
 * 失败则调用 `fail()` 且不推进游标。所有方法在显式租户上下文内执行。
 */
class IntegrationSyncRunService
{
    public function start(
        int $tenantId,
        int $connectionId,
        string $resource,
        string $direction,
        string $triggerType = IntegrationSyncRun::TRIGGER_MANUAL
    ): IntegrationSyncRun {
        return TenantContext::runInTenant($tenantId, fn () => IntegrationSyncRun::create([
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'resource' => $resource,
            'direction' => $direction,
            'trigger_type' => $triggerType,
            'status' => IntegrationSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $stats
     */
    public function finish(int $tenantId, int $runId, ?array $stats = null): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $runId, $stats) {
            $run = IntegrationSyncRun::where('tenant_id', $tenantId)->findOrFail($runId);
            $run->status = IntegrationSyncRun::STATUS_SUCCEEDED;
            $run->finished_at = now();
            $run->stats = $stats;
            $run->last_error = null;
            $run->save();
        });
    }

    /**
     * @param  array<string, mixed>|null  $stats
     */
    public function fail(int $tenantId, int $runId, string $error, ?array $stats = null): void
    {
        TenantContext::runInTenant($tenantId, function () use ($tenantId, $runId, $error, $stats) {
            $run = IntegrationSyncRun::where('tenant_id', $tenantId)->findOrFail($runId);
            $run->status = IntegrationSyncRun::STATUS_FAILED;
            $run->finished_at = now();
            $run->stats = $stats;
            $run->last_error = mb_substr($error, 0, 1000);
            $run->save();
        });
    }
}
