<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\CostSource;

/**
 * 记账归集结果：关联现有领域账本（不新建 Integration 万能账本），
 * 幂等关联同一 eventKey；未知成本保持未知。
 */
final class UsageAttributionResult implements JsonSerializable
{
    public function __construct(
        public readonly string $eventKey,
        public readonly CostSource $costSource,
        public readonly ?StableRef $accountingRef = null,
        public readonly bool $idempotentReplay = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_key' => $this->eventKey,
            'cost_source' => $this->costSource->value,
            'accounting_ref' => $this->accountingRef?->toString(),
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
