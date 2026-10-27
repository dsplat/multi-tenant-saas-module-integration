<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use JsonSerializable;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\PreflightStatus;

/**
 * 预检分项：状态、证据时间与修复项。unknown 不报 pass。
 */
final class PreflightItem implements JsonSerializable
{
    public function __construct(
        public readonly string $check,
        public readonly PreflightStatus $status,
        public readonly string $message = '',
        public readonly ?\DateTimeImmutable $evidenceAt = null,
        public readonly ?string $remediation = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'status' => $this->status->value,
            'message' => $this->message,
            'evidence_at' => $this->evidenceAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'remediation' => $this->remediation,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
