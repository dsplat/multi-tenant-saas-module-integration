<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\BindingResolverContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ConsumerContext;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\ResolutionResult;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * 契约测试/下游接线用 fake：按 (capability, purpose, environment) 返回预设解析结果，
 * 未预设返回 unconfigured 结构化拒绝；记录全部调用。
 */
final class FakeBindingResolver implements BindingResolverContract
{
    /** @var array<string, ResolutionResult> */
    private array $presets = [];

    /** @var list<array{consumer: ConsumerContext, capability: string, purpose: string, environment: string, explicit: ?StableRef}> */
    public array $calls = [];

    public function preset(string $capabilityKey, string $purpose, string $environment, ResolutionResult $result): void
    {
        $this->presets[$this->key($capabilityKey, $purpose, $environment)] = $result;
    }

    public function resolve(
        ConsumerContext $consumer,
        string $capabilityKey,
        string $purpose,
        string $environment,
        ?StableRef $explicitBindingRef = null,
    ): ResolutionResult {
        $this->calls[] = compact('consumer', 'capabilityKey', 'purpose', 'environment', 'explicitBindingRef');

        return $this->presets[$this->key($capabilityKey, $purpose, $environment)]
            ?? ResolutionResult::rejected(new Rejection(
                FailureReason::Unconfigured,
                "no binding preset for {$capabilityKey}/{$purpose}/{$environment}",
            ));
    }

    private function key(string $capabilityKey, string $purpose, string $environment): string
    {
        return "{$capabilityKey}|{$purpose}|{$environment}";
    }
}
