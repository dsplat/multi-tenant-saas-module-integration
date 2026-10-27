<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 已验证的回调证据：只证明「该账号在该端点发出该事件」，
 * 不直接授予租户权限——归属由 CallbackResolver 结合可信事务映射确定。
 */
final class CallbackEvidence
{
    /** @param array<string, scalar|null> $attributes metadata-only */
    public function __construct(
        public readonly StableRef $endpointRef,
        public readonly StableRef $accountRef,
        public readonly string $eventType,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $signatureAlgorithm,
        public readonly array $attributes = [],
        public readonly ?string $correlationHint = null,
    ) {}
}
