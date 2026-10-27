<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\ServiceSource;
use MultiTenantSaas\Modules\Integration\Contracts\ServiceContract;

/**
 * 解析结果：一次调用最终使用的服务实例的完整指纹。
 *
 * 序列化约定（Playbook v1 §3.2）：大整数 ID 一律字符串、UTC 时间、
 * contract_version 与 source 显式；凭证原文不出现在本对象任何字段。
 */
final class ResolvedService implements JsonSerializable
{
    public function __construct(
        public readonly ServiceSource $source,
        public readonly ConsumerContext $consumer,
        public readonly StableRef $accountRef,
        public readonly ResourceScope $resourceScope,
        public readonly string $configVersion,
        public readonly CredentialRef $credentialRef,
        public readonly string $environment,
        public readonly ?string $region,
        public readonly ?StableRef $bindingRef,
        public readonly \DateTimeImmutable $resolvedAt,
        public readonly string $contractVersion = ServiceContract::VERSION,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract_version' => $this->contractVersion,
            'source' => $this->source->value,
            'consumer' => [
                'scope_kind' => $this->consumer->scopeKind->value,
                'tenant_id' => $this->consumer->tenantId !== null ? (string) $this->consumer->tenantId : null,
                'operation_purpose' => $this->consumer->operationPurpose,
                'correlation_id' => $this->consumer->correlationId,
            ],
            'binding_ref' => $this->bindingRef?->toString(),
            'account_ref' => $this->accountRef->toString(),
            'resource_scope' => ['kind' => $this->resourceScope->kind, 'id' => (string) $this->resourceScope->id],
            'config_version' => $this->configVersion,
            'credential_ref' => $this->credentialRef->toString(),
            'environment' => $this->environment,
            'region' => $this->region,
            'resolved_at' => $this->formatUtc($this->resolvedAt),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function formatUtc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
