<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 待激活方案（预检输入）：能力、用途、环境、供应商与候选账号/端点。
 */
final class ProvisionPlan
{
    /** @param list<string> $endpointUrls */
    public function __construct(
        public readonly string $capabilityKey,
        public readonly string $purpose,
        public readonly string $environment,
        public readonly string $provider,
        public readonly array $endpointUrls = [],
        public readonly ?StableRef $accountRef = null,
    ) {}
}
