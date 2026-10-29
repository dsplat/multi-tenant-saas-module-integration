<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Exceptions\DomainException;
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
 * - 幂等键带消费方命名空间（scope:tenant:eventKey），同键不同内容视为冲突并拒绝
 *   ——不允许跨租户误合并、不允许改内容重放；
 * - 租户 BYO（source=tenant，租户直接付供应商）只计量，不计入平台应收；
 * - 平台运维消耗（platform_operation）独立 scope，不进租户账单、不用 MAX-ID 填空；
 * - 未知成本保持未知（cost_source=unknown 且 price_version=NULL，不猜默认价）；
 * - 对账按 metric_type 分组（token/条数/存储量等不同量纲不可相加），DB 级 SUM 精确汇总。
 */
class UsageAttributionService implements UsageAttributionContract
{
    public function __construct(
        private readonly IdGeneratorContract $idGenerator,
    ) {}

    public function record(UsageEvent $event): UsageAttributionResult
    {
        $quantity = $this->canonicalQuantity($event->quantity);
        $occurredAt = $event->occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $namespacedKey = $this->namespacedKey($event);
        $existing = $this->findByEventKey($namespacedKey);

        if ($existing !== null) {
            $this->assertSameContent($existing, $event, $namespacedKey);

            return $this->replayResult($event, $existing);
        }

        $consumer = $event->service->consumer;

        try {
            $id = $this->idGenerator->generate();
            DB::table('usage_records')->insert([
                'usage_record_id' => $id,
                'tenant_id' => $consumer->tenantId,
                'metric_type' => $event->dimension,
                'value' => $quantity,
                'period' => $occurredAt->format('Ym'),
                'recorded_at' => $occurredAt,
                'event_key' => $namespacedKey,
                'account_ref' => $event->service->accountRef->toString(),
                'binding_ref' => $event->service->bindingRef?->toString(),
                'source' => $event->service->source->value,
                'scope_kind' => $consumer->scopeKind->value,
                'cost_source' => $event->costSource->value,
                'price_version' => $event->costSource === CostSource::Known ? $event->priceVersion : null,
                'metadata' => json_encode(['correlation_id' => $consumer->correlationId], JSON_THROW_ON_ERROR),
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        } catch (\Throwable $e) {
            // 并发重复：唯一键收敛为幂等重放；内容不一致则由 assertSameContent 拒绝
            $existing = $this->findByEventKey($namespacedKey);
            if ($existing === null) {
                throw $e;
            }

            $this->assertSameContent($existing, $event, $namespacedKey);

            return $this->replayResult($event, $existing);
        }

        return new UsageAttributionResult(
            eventKey: $event->eventKey,
            costSource: $event->costSource,
            accountingRef: StableRef::make('MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord', (string) $id),
        );
    }

    /**
     * 对账（dry-run/结算前核对，WP-G）：按 metric_type 分组——token、短信条数、
     * 存储量等不同量纲不可相加；金额（微元）单量纲可汇总。
     *
     * 应收范围 = 消费方租户 + source=inherit_platform（平台垫付再向租户结算）；
     * BYO（source=tenant）只计量不重复计费；platform_operation 独立隔离。
     *
     * @return array<string, mixed>
     */
    public function reconcile(?int $tenantId, string $period): array
    {
        $costs = DB::table('cost_allocations')
            ->where('period', $period)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        $sumMicro = static fn (\Closure $filter): int => (int) collect($costs)->filter($filter)->sum('amount');

        return [
            'period' => $period,
            'tenant_id' => $tenantId,
            // 分量纲用量（decimal 字符串，DB SUM 精确；不同 metric_type 不可互相加总）
            'usage_by_metric' => $this->sumByMetric($tenantId, $period, []),
            'billable_by_metric' => $this->sumByMetric($tenantId, $period, [
                ['scope_kind', '=', ConsumerScopeKind::Tenant->value],
                ['source', '=', ServiceSource::InheritPlatform->value],
            ]),
            'byo_by_metric' => $this->sumByMetric($tenantId, $period, [
                ['scope_kind', '=', ConsumerScopeKind::Tenant->value],
                ['source', '=', ServiceSource::Tenant->value],
            ]),
            'platform_operation_by_metric' => $this->sumByMetric($tenantId, $period, [
                ['scope_kind', '=', ConsumerScopeKind::PlatformOperation->value],
            ]),
            'unknown_cost_by_metric' => $this->sumByMetric($tenantId, $period, [
                ['cost_source', '=', CostSource::Unknown->value],
            ]),
            'unknown_cost_events' => (int) DB::table('usage_records')
                ->where('period', $period)
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('cost_source', CostSource::Unknown->value)
                ->count(),
            // 金额为单量纲（微元）：可跨 metric 汇总
            'supplier_cost_micro' => $sumMicro(fn ($row): bool => (string) $row->cost_source !== CostSource::Unknown->value),
            'unknown_cost_micro' => $sumMicro(fn ($row): bool => (string) $row->cost_source === CostSource::Unknown->value),
            'price_versions' => DB::table('usage_records')
                ->where('period', $period)
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereNotNull('price_version')
                ->distinct()
                ->pluck('price_version')
                ->values()
                ->all(),
        ];
    }

    /**
     * 分量纲精确汇总（DB SUM，返回 decimal 字符串）。
     *
     * @param  array<int, array{0: string, 1: string, 2: mixed}>  $where
     * @return array<string, string>
     */
    private function sumByMetric(?int $tenantId, string $period, array $where): array
    {
        return DB::table('usage_records')
            ->select('metric_type', DB::raw('SUM(value) as total'))
            ->where('period', $period)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($where !== [], fn ($query) => $query->where($where))
            ->groupBy('metric_type')
            ->orderBy('metric_type')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->metric_type => $this->scale4((string) $row->total)])
            ->all();
    }

    /**
     * decimal(18,4) 定点化（纯字符串，不走 float）：SUM 结果补足 4 位小数。
     */
    private function scale4(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0.0000';
        }

        $int = $value;
        $frac = '';
        if (str_contains($value, '.')) {
            [$int, $frac] = explode('.', $value, 2);
        }

        return $int . '.' . substr(str_pad($frac, 4, '0'), 0, 4);
    }

    /**
     * 幂等键命名空间：消费方 scope + 租户 + 调用方 eventKey（防跨租户同名误合并）。
     */
    private function namespacedKey(UsageEvent $event): string
    {
        $consumer = $event->service->consumer;

        return $consumer->scopeKind->value . ':' . ($consumer->tenantId ?? '-') . ':' . $event->eventKey;
    }

    /**
     * 重放前内容核对：同键不同内容 = 冲突，拒绝而不是吞并（防漏记/跨租户引用泄漏）。
     */
    private function assertSameContent(object $existing, UsageEvent $event, string $key): void
    {
        $consumer = $event->service->consumer;

        $same = (int) ($existing->tenant_id ?? 0) === (int) ($consumer->tenantId ?? 0)
            && (string) $existing->account_ref === $event->service->accountRef->toString()
            && (string) ($existing->binding_ref ?? '') === ($event->service->bindingRef?->toString() ?? '')
            && (string) $existing->metric_type === $event->dimension
            && $this->canonicalQuantity((string) $existing->value) === $this->canonicalQuantity($event->quantity)
            && (string) ($existing->price_version ?? '') === (string) ($event->costSource === CostSource::Known ? $event->priceVersion : null)
            && (string) $existing->period === $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Ym')
            && (string) $existing->recorded_at === $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            && (string) $existing->source === $event->service->source->value
            && (string) $existing->cost_source === $event->costSource->value;

        if (! $same) {
            throw new DomainException("event_key 冲突：同键不同内容，拒绝重放（{$key}）");
        }
    }

    private function canonicalQuantity(string $value): string
    {
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d{1,4}))?$/D', $value, $matches)) {
            throw new DomainException('Invalid usage quantity');
        }

        $integer = ltrim($matches[2], '0') ?: '0';
        if (strlen($integer) > 14) {
            throw new DomainException('Usage quantity exceeds decimal(18,4) range');
        }
        $fraction = str_pad($matches[3] ?? '', 4, '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || $fraction !== '0000') ? '-' : '';

        return $sign . $integer . '.' . $fraction;
    }

    private function replayResult(UsageEvent $event, object $existing): UsageAttributionResult
    {
        return new UsageAttributionResult(
            eventKey: $event->eventKey,
            costSource: CostSource::from((string) $existing->cost_source),
            accountingRef: StableRef::make('MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord', (string) $existing->usage_record_id),
            idempotentReplay: true,
        );
    }

    private function findByEventKey(string $namespacedKey): ?object
    {
        return DB::table('usage_records')->where('event_key', $namespacedKey)->first();
    }
}
