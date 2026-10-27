<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * CallbackVerifier 结果：已验证证据或结构化拒绝。
 */
final class VerificationResult
{
    private function __construct(
        public readonly ?CallbackEvidence $evidence,
        public readonly ?Rejection $rejection,
    ) {}

    public static function verified(CallbackEvidence $evidence): self
    {
        return new self($evidence, null);
    }

    public static function rejected(Rejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function isVerified(): bool
    {
        return $this->evidence !== null;
    }
}
