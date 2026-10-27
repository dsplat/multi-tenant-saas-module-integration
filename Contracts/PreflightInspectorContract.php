<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\PreflightReport;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProbePermissions;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ProvisionPlan;

/**
 * 接入预检：分项 pass/fail/unknown/not_applicable + 证据时间 + 修复项。
 * 未授权探测项报 unknown/not_applicable，不得报 pass（WP-H 约束）。
 */
interface PreflightInspectorContract
{
    public function inspect(ProvisionPlan $plan, ProbePermissions $permissions): PreflightReport;
}
