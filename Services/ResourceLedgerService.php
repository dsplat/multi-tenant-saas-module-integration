<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Integration\Models\IntegrationResourceLedger;

/**
 * 稀缺资源台账（WP-H / ADR §1.3）：供应商规则与账号资源实例分列，
 * 支持占用/修改权记录、本地槽位预约与实测值漂移标记。
 *
 * 本地预约只防本平台并发占用，不锁供应商后台；激活前须复核外部实际值。
 */
class ResourceLedgerService
{
    /**
     * 登记一行台账（规则或实例，幂等覆盖同键 active 行）。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): IntegrationResourceLedger
    {
        $existing = IntegrationResourceLedger::query()
            ->where('scope', $attributes['scope'])
            ->where('capability_key', $attributes['capability_key'])
            ->where('resource_kind', $attributes['resource_kind'])
            ->where('environment', $attributes['environment'] ?? 'production')
            ->where('slot_key', $attributes['slot_key'] ?? null)
            ->where('account_ref', $attributes['account_ref'] ?? null)
            ->where('status', IntegrationResourceLedger::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing;
        }

        return IntegrationResourceLedger::create($attributes);
    }

    /**
     * 平台范围供应商规则（tenant_id NULL）+ 租户实例的显式合并查询；
     * 调用方必须显式声明租户范围，NULL 不作通配。
     *
     * @return Collection<int, IntegrationResourceLedger>
     */
    public function rows(?int $tenantId, string $capabilityKey, string $resourceKind, string $environment = 'production')
    {
        return IntegrationResourceLedger::query()
            ->where('capability_key', $capabilityKey)
            ->where('resource_kind', $resourceKind)
            ->where('environment', $environment)
            ->where('status', IntegrationResourceLedger::STATUS_ACTIVE)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderByDesc('ledger_id')
            ->get();
    }

    /**
     * 账号资源实例行（按 StableRef 关联，与租户显式解耦）。
     *
     * @return Collection<int, IntegrationResourceLedger>
     */
    public function accountRows(
        ?string $accountRef,
        string $capabilityKey,
        string $resourceKind,
        string $environment = 'production',
        ?string $status = IntegrationResourceLedger::STATUS_ACTIVE,
    ) {
        return IntegrationResourceLedger::query()
            ->where('scope', IntegrationResourceLedger::SCOPE_ACCOUNT_INSTANCE)
            ->where('capability_key', $capabilityKey)
            ->where('resource_kind', $resourceKind)
            ->where('environment', $environment)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($accountRef !== null, fn ($query) => $query->where('account_ref', $accountRef))
            ->orderByDesc('ledger_id')
            ->get();
    }

    /**
     * 本地槽位预约：占用检查 + 行锁，防本平台并发重复预约。
     *
     * @return array{ok: bool, reason?: string, row?: IntegrationResourceLedger}
     */
    public function reserveSlot(
        ?int $tenantId,
        string $capabilityKey,
        string $resourceKind,
        string $slotKey,
        string $owner,
        ?\DateTimeImmutable $until = null,
    ): array {
        return DB::transaction(function () use ($capabilityKey, $resourceKind, $slotKey, $owner, $until) {
            $row = IntegrationResourceLedger::query()
                ->where('scope', IntegrationResourceLedger::SCOPE_ACCOUNT_INSTANCE)
                ->where('capability_key', $capabilityKey)
                ->where('resource_kind', $resourceKind)
                ->where('slot_key', $slotKey)
                ->where('status', IntegrationResourceLedger::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['ok' => false, 'reason' => 'slot_not_registered'];
            }

            $heldByOther = $row->reserved_by !== null
                && $row->reserved_by !== $owner
                && ($row->reserved_until === null || $row->reserved_until->isFuture());

            if ($heldByOther) {
                return ['ok' => false, 'reason' => 'slot_reserved'];
            }

            $row->update(['reserved_by' => $owner, 'reserved_until' => $until]);

            return ['ok' => true, 'row' => $row];
        });
    }

    /**
     * 记录实测值并做漂移标记：与 expected_value 不一致 → status=drifted。
     *
     * @param  array<string, mixed>  $observed
     */
    public function recordObserved(int $ledgerId, array $observed, ?\DateTimeImmutable $evidenceAt = null): IntegrationResourceLedger
    {
        $row = IntegrationResourceLedger::query()->findOrFail($ledgerId);
        $row->observed_value = $observed;
        $row->evidence_at = $evidenceAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $row->status = ($row->expected_value !== null && $row->expected_value != $observed)
            ? IntegrationResourceLedger::STATUS_DRIFTED
            : IntegrationResourceLedger::STATUS_ACTIVE;
        $row->save();

        return $row;
    }
}
