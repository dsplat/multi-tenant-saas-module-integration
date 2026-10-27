<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * 结构化拒绝：业务拒绝与临时故障由 transient 区分，不以 null 代表失败。
 *
 * details 仅允许 metadata（键名、状态、候选计数等），禁止塞入 secret/token/正文。
 */
final class Rejection implements JsonSerializable
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public readonly FailureReason $reason,
        public readonly string $message,
        public readonly ?string $traceId = null,
        public readonly array $details = [],
    ) {}

    public function isTransient(): bool
    {
        return $this->reason->isTransient();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->reason->value,
                'message' => $this->message,
                'transient' => $this->isTransient(),
                'trace_id' => $this->traceId,
                'details' => $this->details,
            ],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
