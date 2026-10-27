<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\PreflightReport;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProbePermissions;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProvisionPlan;
use MultiTenantSaas\Modules\Integration\Contracts\PreflightInspectorContract;

/**
 * fake 预检器：返回预设报告并记录输入；未预设返回空项报告。
 */
final class FakePreflightInspector implements PreflightInspectorContract
{
    private ?PreflightReport $report = null;

    /** @var list<array{plan: ProvisionPlan, permissions: ProbePermissions}> */
    public array $calls = [];

    public function willReport(PreflightReport $report): void
    {
        $this->report = $report;
    }

    public function inspect(ProvisionPlan $plan, ProbePermissions $permissions): PreflightReport
    {
        $this->calls[] = compact('plan', 'permissions');

        return $this->report ?? new PreflightReport([], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
