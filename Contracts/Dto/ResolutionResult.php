<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * BindingResolver 结果：成功给 ResolvedService，失败给结构化 Rejection。
 * 候选多义必须返回 ambiguous_binding，禁止任取第一条。
 */
final class ResolutionResult
{
    private function __construct(
        public readonly ?ResolvedService $service,
        public readonly ?Rejection $rejection,
    ) {}

    public static function resolved(ResolvedService $service): self
    {
        return new self($service, null);
    }

    public static function rejected(Rejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function isResolved(): bool
    {
        return $this->service !== null;
    }
}
