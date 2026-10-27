<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use InvalidArgumentException;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\ConsumerScopeKind;

/**
 * 消费方上下文。
 *
 * - tenant 范围 tenant_id 必填；platform_operation 必须显式运维用途与授权。
 * - actor/correlationId 仅用于审计与追踪，不得作为租户选择依据。
 */
final class ConsumerContext
{
    private function __construct(
        public readonly ConsumerScopeKind $scopeKind,
        public readonly ?int $tenantId,
        public readonly ?string $operationPurpose,
        public readonly ?string $operationAuthorization,
        public readonly ?string $actor,
        public readonly string $correlationId,
    ) {}

    public static function tenant(int $tenantId, string $correlationId, ?string $actor = null): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenant scope requires tenant_id');
        }

        return new self(ConsumerScopeKind::Tenant, $tenantId, null, null, $actor, $correlationId);
    }

    public static function platformOperation(
        string $purpose,
        string $authorization,
        string $correlationId,
        ?string $actor = null,
    ): self {
        if ($purpose === '' || $authorization === '') {
            throw new InvalidArgumentException('platform_operation scope requires purpose and authorization');
        }

        return new self(ConsumerScopeKind::PlatformOperation, null, $purpose, $authorization, $actor, $correlationId);
    }

    public function requiresTenant(int $tenantId): bool
    {
        return $this->scopeKind === ConsumerScopeKind::Tenant && $this->tenantId === $tenantId;
    }
}
