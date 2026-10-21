<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Closure;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Integration\Models\ExternalMapping;

/**
 * 外部映射服务（迁移导入时写入，支撑幂等/对账/追溯）
 *
 * 不耦合业务逻辑：仅做映射存储，不触发业务动作。
 * 框架标准件（BL-163）：自 scrm TenantOps 物理归位，返回结果语义不变；
 * 租户上下文处理对齐 v3.2.0 BL-159——每个方法在 `runInTenant` 内执行：
 * 调用方已装载同租户上下文时行为不变，无上下文时临时建立并恢复，
 * 显式租户与既有上下文冲突则 fail-closed。
 */
class ExternalMappingService
{
    /**
     * 写入/更新映射（幂等：同一外部资源仅一条）
     *
     * @param  array|null  $rawSnapshot  导入时原始数据快照（对账/追溯用）
     * @param  string|null  $batchId  导入批次（manifest.batch_id，按批次撤回/对账用）
     */
    public function upsert(
        int $tenantId,
        string $sourceSystem,
        string $sourceType,
        string $sourceId,
        string $localType,
        int $localId,
        ?array $rawSnapshot = null,
        ?string $batchId = null
    ): ExternalMapping {
        return $this->withinTenant($tenantId, fn () => ExternalMapping::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'source_system' => $sourceSystem,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'local_type' => $localType,
                'local_id' => $localId,
                'raw_snapshot' => $rawSnapshot,
                'batch_id' => $batchId,
            ]
        ));
    }

    /**
     * 外部资源是否已导入（增量迁移幂等判定）
     */
    public function exists(int $tenantId, string $sourceSystem, string $sourceType, string $sourceId): bool
    {
        return $this->withinTenant($tenantId, fn () => ExternalMapping::where('tenant_id', $tenantId)
            ->where('source_system', $sourceSystem)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists());
    }

    /**
     * 外部 ID → 本地 ID
     */
    public function resolveBySource(int $tenantId, string $sourceSystem, string $sourceType, string $sourceId): ?ExternalMapping
    {
        return $this->withinTenant($tenantId, fn () => ExternalMapping::where('tenant_id', $tenantId)
            ->where('source_system', $sourceSystem)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first());
    }

    /**
     * 本地实体 → 外部来源（回滚参考/来源展示）
     *
     * @return array<int, ExternalMapping>
     */
    public function resolveByLocal(int $tenantId, string $localType, int $localId): array
    {
        return $this->withinTenant($tenantId, fn () => ExternalMapping::where('tenant_id', $tenantId)
            ->where('local_type', $localType)
            ->where('local_id', $localId)
            ->get()
            ->all());
    }

    /**
     * 对账统计：按外部系统+资源类型计数
     *
     * @return array<string, int> key="source_system:source_type"
     */
    public function stats(int $tenantId, ?string $sourceSystem = null): array
    {
        return $this->withinTenant($tenantId, function () use ($tenantId, $sourceSystem) {
            $query = ExternalMapping::where('tenant_id', $tenantId);

            if ($sourceSystem !== null) {
                $query->where('source_system', $sourceSystem);
            }

            return $query->get(['source_system', 'source_type'])
                ->groupBy(fn ($m) => $m->source_system . ':' . $m->source_type)
                ->map(fn ($group) => $group->count())
                ->all();
        });
    }

    /**
     * 批次内全部映射（按批次撤回/对账）
     *
     * @return array<int, ExternalMapping>
     */
    public function mappingsForBatch(int $tenantId, string $batchId): array
    {
        return $this->withinTenant($tenantId, fn () => ExternalMapping::where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->orderBy('mapping_id')
            ->get()
            ->all());
    }

    /**
     * 在显式租户内执行映射读写：同租户上下文直接执行，缺失时临时建立并恢复，
     * 冲突上下文由 TenantContext 抛 PermissionDeniedException（不静默改写）。
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withinTenant(int $tenantId, Closure $work): mixed
    {
        return TenantContext::runInTenant($tenantId, $work);
    }
}
