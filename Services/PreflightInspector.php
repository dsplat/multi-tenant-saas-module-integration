<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\PreflightItem;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\PreflightReport;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProbePermissions;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProvisionPlan;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\PreflightStatus;
use MultiTenantSaas\Modules\Integration\Contracts\PreflightInspectorContract;
use MultiTenantSaas\Modules\Integration\Models\IntegrationResourceLedger;

/**
 * 接入预检器（WP-H / ADR §1.3）。
 *
 * 只读台账与本地证据，不做任意 URL 抓取（SSRF 面为零）；需要外部探测而
 * 未授权时报 unknown——unknown 永不升格为 pass，「密钥存在即 ready」不存在。
 */
class PreflightInspector implements PreflightInspectorContract
{
    public function __construct(
        private readonly ResourceLedgerService $ledger,
    ) {}

    public function inspect(ProvisionPlan $plan, ProbePermissions $permissions): PreflightReport
    {
        $items = [
            $this->checkDomainSlot($plan),
            $this->checkCallbackEndpoint($plan),
            $this->checkExportIp($plan),
            $this->checkQualification($plan),
            $this->checkAuthorization($plan),
        ];

        return new PreflightReport($items, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function checkDomainSlot(ProvisionPlan $plan): PreflightItem
    {
        $instance = $this->ledger
            ->accountRows($plan->accountRef?->toString(), $plan->capabilityKey, IntegrationResourceLedger::KIND_DOMAIN_SLOT, $plan->environment)
            ->first();

        if ($instance === null) {
            return $this->item('domain_slot', PreflightStatus::Unknown, '未登记域名槽位台账，无法判定', '登记账号的槽位配额/占用/修改权');
        }

        if ($instance->modify_rights === IntegrationResourceLedger::RIGHTS_NONE
            || $instance->modify_rights === IntegrationResourceLedger::RIGHTS_SUPPLIER) {
            return $this->item('domain_slot', PreflightStatus::Fail, '无域名修改权（' . $instance->modify_rights . '）', '取得修改权或改走可共存的回调方案');
        }

        if ($instance->slots_total !== null && $instance->slots_used >= $instance->slots_total) {
            return $this->item('domain_slot', PreflightStatus::Fail, '域名槽位已满（' . $instance->slots_used . '/' . $instance->slots_total . '）', '释放槽位或走供应商支持的共存/委托方案', $instance->evidence_at);
        }

        return $this->item('domain_slot', PreflightStatus::Pass, '槽位可用', null, $instance->evidence_at);
    }

    private function checkCallbackEndpoint(ProvisionPlan $plan): PreflightItem
    {
        $registered = $this->ledger
            ->accountRows($plan->accountRef?->toString(), $plan->capabilityKey, IntegrationResourceLedger::KIND_CALLBACK_ENDPOINT, $plan->environment)
            ->first(fn (IntegrationResourceLedger $row): bool => $row->observed_value !== null || $row->expected_value !== null);

        if ($registered === null) {
            return $this->item('callback_endpoint', PreflightStatus::Fail, '回调端点未登记', '登记并验证回调端点（协议回调必须精确注册）');
        }

        return $this->item('callback_endpoint', PreflightStatus::Pass, '回调端点已登记', null, $registered->evidence_at);
    }

    private function checkExportIp(ProvisionPlan $plan): PreflightItem
    {
        // 含 drifted 行：漂移证据必须可见。全局 ID 随机无序，判定按显式优先级
        // （漂移 > 有实测 > 未知），不依赖行序。
        $rows = $this->ledger
            ->accountRows($plan->accountRef?->toString(), $plan->capabilityKey, IntegrationResourceLedger::KIND_EXPORT_IP, $plan->environment, status: null);

        $drifted = $rows->first(fn (IntegrationResourceLedger $row): bool => $row->status === IntegrationResourceLedger::STATUS_DRIFTED);
        if ($drifted !== null) {
            return $this->item('export_ip', PreflightStatus::Fail, '出口 IP 漂移（与登记值不一致）', '重新登记对方白名单并保留旧出口窗口', $drifted->evidence_at);
        }

        $observed = $rows->first(fn (IntegrationResourceLedger $row): bool => $row->observed_value !== null);
        if ($observed === null) {
            // 实测出口缺失：无外部探测授权时必须报 unknown，不得报 pass
            return $this->item('export_ip', PreflightStatus::Unknown, '缺少出口 IP 实测证据', '在真实出口机器上回填实测值后再预检');
        }

        return $this->item('export_ip', PreflightStatus::Pass, '出口 IP 与登记一致', null, $observed->evidence_at);
    }

    private function checkQualification(ProvisionPlan $plan): PreflightItem
    {
        $row = $this->ledger
            ->rows(null, $plan->capabilityKey, IntegrationResourceLedger::KIND_QUALIFICATION, $plan->environment)
            ->first();

        if ($row === null) {
            return $this->item('qualification', PreflightStatus::Unknown, '资质台账未登记', '登记平台资质/发布状态与复核日期');
        }

        if ($row->review_by !== null && $row->review_by->isPast()) {
            return $this->item('qualification', PreflightStatus::Fail, '资质证据已过复核期', '复核供应商资质与产品开通状态', $row->evidence_at);
        }

        return $this->item('qualification', PreflightStatus::Pass, '资质证据在有效期内', null, $row->evidence_at);
    }

    private function checkAuthorization(ProvisionPlan $plan): PreflightItem
    {
        $row = $this->ledger
            ->accountRows($plan->accountRef?->toString(), $plan->capabilityKey, IntegrationResourceLedger::KIND_AUTHORIZATION, $plan->environment)
            ->first();

        if ($row === null) {
            return $this->item('authorization', PreflightStatus::Fail, '缺少客户授权记录', '由真正的账号授权人完成供应商授权');
        }

        return $this->item('authorization', PreflightStatus::Pass, '授权记录有效', null, $row->evidence_at);
    }

    private function item(string $check, PreflightStatus $status, string $message, ?string $remediation, ?\DateTimeInterface $evidenceAt = null): PreflightItem
    {
        $at = $evidenceAt instanceof \DateTimeImmutable
            ? $evidenceAt
            : ($evidenceAt !== null ? \DateTimeImmutable::createFromInterface($evidenceAt) : null);

        return new PreflightItem($check, $status, $message, $at, $remediation);
    }
}
