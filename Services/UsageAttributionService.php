<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageAttributionResult;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageEvent;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\ConsumerScopeKind;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\CostSource;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\ServiceSource;
use MultiTenantSaas\Modules\Integration\Contracts\UsageAttributionContract;

/**
 * 用量归集（WP-G / OPM-12）：按实际服务引用与消费方记账，幂等关联既有领域账本。
 *
 * - 不建 Integration 万能账本：只写 Billing `usage_records`（计量）并带归集维度，
 *   成本行（`cost_allocations`）由领域侧按价格版本落，本服务只对账；
 * - 租户 BYO（source=tenant，租户直接付供应商）只计量，不计入平台应收；
 * - 平台运维消耗（platform_operation）独立 scope，不进租户账单、不用 MAX-ID 填空；
 * - 未知成本保持未知（cost_source=unknown 且 price_version=NULL，不猜默认价）。
 */
class UsageAttributionService implements UsageAttributionContract
{
    public function __construct(
        private readonly IdGeneratorContract $idGenerator,
    ) {}

    public function record(UsageEvent $event): UsageAttributionResult
    {
        $existing = $this->findByEventKey($event->eventKey);
        if ($existing !== null) {
            return new UsageAttributionResult(
                eventKey: $event->eventKey,
                costSource: CostSource::from((string) $existing->cost_source),
                accountingRef: StableRef::make('MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord', (string) $existing->usage_record_id),
                idempotentReplay: true,
            );
        }

        $consumer = $event->service->consumer;

        try {
            $id = $this->idGenerator->generate();
            DB::table('usage_records')->insert([
                'usage_record_id' => $id,
                'tenant_id' => $consumer->tenantId,
                'metric_type' => $event->dimension,
                'value' => $event->quantity,
                // period 沿域侧 UsageService 口径（Ym），对账与聚合可比
                'period' => $event->occurredAt->format('Ym'),
                'recorded_at' => $event->occurredAt,
                'event_key' => $event->eventKey,
                'account_ref' => $event->service->accountRef->toString(),
                'binding_ref' => $event->service->bindingRef?->toString(),
                'source' => $event->service->source->value,
                'scope_kind' => $consumer->scopeKind->value,
                'cost_source' => $event->costSource->value,
                'price_version' => $event->costSource === CostSource::Known ? $event->priceVersion : null,
                'metadata' => json_encode(['correlation_id' => $consumer->correlationId], JSON_THROW_ON_ERROR),
                'created_at' => $event->occurredAt,
                'updated_at' => $event->occurredAt,
            ]);
        } catch (\Throwable $e) {
            // 并发重复：唯一 event_key 收敛为幂等重放
            $existing = $this->findByEventKey($event->eventKey);
            if ($existing === null) {
                throw $e;
            }

            return new UsageAttributionResult(
                eventKey: $event->eventKey,
                costSource: CostSource::from((string) $existing->cost_source),
                accountingRef: StableRef::make('MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord', (string) $existing->usage_record_id),
                idempotentReplay: true,
            );
        }

        return new UsageAttributionResult(
            eventKey: $event->eventKey,
            costSource: $event->costSource,
            accountingRef: StableRef::make('MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord', (string) $id),
        );
    }

    /**
     * 对账（dry-run/结算前核对，WP-G）：用量、平台应收范围、BYO 与运维隔离、
     * 未知成本与价格版本清单、供应商成本（微元）汇总。
     *
     * 应收范围 = 消费方租户 + source=inherit_platform（平台垫付再向租户结算）；
     * BYO（source=tenant）只计量不重复计费；platform_operation 独立隔离。
     *
     * @return array<string, mixed>
     */
    public function reconcile(?int $tenantId, string $period): array
    {
        $rows = DB::table('usage_records')
            ->where('period', $period)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        $sum = static fn ($rows, \Closure $filter): string => number_format(
            (float) $rows->filter($filter)->sum(fn ($row) => (float) $row->value),
            4,
            '.',
            '',
        );

        $costs = DB::table('cost_allocations')
            ->where('period', $period)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        return [
            'period' => $period,
            'tenant_id' => $tenantId,
            'usage_total' => $sum($rows, static fn ($row): bool => true),
            'billable_usage' => $sum($rows, static fn ($row): bool => $row->scope_kind === ConsumerScopeKind::Tenant->value
                && $row->source === ServiceSource::InheritPlatform->value),
            'byo_usage' => $sum($rows, static fn ($row): bool => $row->scope_kind === ConsumerScopeKind::Tenant->value
                && $row->source === ServiceSource::Tenant->value),
            'platform_operation_usage' => $sum($rows, static fn ($row): bool => $row->scope_kind === ConsumerScopeKind::PlatformOperation->value),
            'unknown_cost_events' => (int) $rows->where('cost_source', CostSource::Unknown->value)->count(),
            'unknown_cost_usage' => $sum($rows, static fn ($row): bool => $row->cost_source === CostSource::Unknown->value),
            'supplier_cost_micro' => (int) $costs->where('cost_source', '!=', CostSource::Unknown->value)->sum('amount'),
            'unknown_cost_micro' => (int) $costs->where('cost_source', CostSource::Unknown->value)->sum('amount'),
            'price_versions' => $rows->pluck('price_version')->filter()->unique()->values()->all(),
        ];
    }

    private function findByEventKey(string $eventKey): ?object
    {
        return DB::table('usage_records')->where('event_key', $eventKey)->first();
    }
}
