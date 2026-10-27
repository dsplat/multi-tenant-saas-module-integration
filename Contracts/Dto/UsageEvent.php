<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\CostSource;

/**
 * 用量事件：实际服务引用 + 消费方 + 量纲 + 成本来源。
 *
 * eventKey 为幂等键；数量用十进制字符串（禁 float）；未知成本保持
 * CostSource::Unknown，priceVersion 为 null，不猜默认价。
 */
final class UsageEvent implements JsonSerializable
{
    public function __construct(
        public readonly string $eventKey,
        public readonly ResolvedService $service,
        public readonly string $dimension,
        public readonly string $quantity,
        public readonly CostSource $costSource,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $priceVersion = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_key' => $this->eventKey,
            'service' => $this->service->toArray(),
            'dimension' => $this->dimension,
            'quantity' => $this->quantity,
            'cost_source' => $this->costSource->value,
            'price_version' => $this->costSource === CostSource::Known ? $this->priceVersion : null,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
