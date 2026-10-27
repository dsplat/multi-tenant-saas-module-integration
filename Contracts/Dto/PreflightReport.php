<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\PreflightStatus;

/**
 * 预检报告：分项结果集合 + 总判定（任一 fail 即 fail；unknown 不升格为 pass）。
 */
final class PreflightReport implements JsonSerializable
{
    /** @param list<PreflightItem> $items */
    public function __construct(
        public readonly array $items,
        public readonly \DateTimeImmutable $generatedAt,
    ) {}

    public function overall(): PreflightStatus
    {
        $overall = PreflightStatus::Pass;
        foreach ($this->items as $item) {
            if ($item->status === PreflightStatus::Fail) {
                return PreflightStatus::Fail;
            }
            if ($item->status === PreflightStatus::Unknown) {
                $overall = PreflightStatus::Unknown;
            }
        }

        return $overall;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'overall' => $this->overall()->value,
            'generated_at' => $this->generatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'items' => array_map(static fn (PreflightItem $item): array => $item->toArray(), $this->items),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
