<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageAttributionResult;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageEvent;

/**
 * 用量归集：按实际服务引用与消费方记账，幂等关联现有领域账本。
 *
 * 未知成本保持未知；租户 BYO 直接向供应商支付的费用不重复计入平台应收
 * （Playbook v1 §3.1-7）。
 */
interface UsageAttributionContract
{
    public function record(UsageEvent $event): UsageAttributionResult;
}
