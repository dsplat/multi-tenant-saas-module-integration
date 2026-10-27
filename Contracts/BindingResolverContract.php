<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\ConsumerContext;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ResolutionResult;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;

/**
 * 服务绑定解析（WP-C / Playbook v1 §3.2）。
 *
 * 输入消费方、能力、用途、环境与可选显式绑定引用；输出 ResolvedService 或
 * 结构化拒绝。候选多义返回 ambiguous_binding，禁止任取第一条；显式选择被拒绝
 * 时终止，不改用其他账号（ADR §3.2）。
 */
interface BindingResolverContract
{
    public function resolve(
        ConsumerContext $consumer,
        string $capabilityKey,
        string $purpose,
        string $environment,
        ?StableRef $explicitBindingRef = null,
    ): ResolutionResult;
}
