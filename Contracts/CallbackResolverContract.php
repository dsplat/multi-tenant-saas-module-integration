<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackEvidence;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackResolution;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\TransactionMapping;

/**
 * 回调归属解析：已验证证据 + 可信事务/订单映射 → 消费方、账号/绑定、资源、事件、关联 ID。
 *
 * 签名有效不等于归属成立：多租户共用商户时以可信订单/事务映射为准
 * （ADR §9.3 OPM-10）。
 */
interface CallbackResolverContract
{
    public function resolve(CallbackEvidence $evidence, TransactionMapping $mapping): CallbackResolution|Rejection;
}
