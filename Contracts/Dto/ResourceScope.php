<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 资源范围：能力对应的具体资源（如知识库、存储桶、商户号）。
 *
 * 共享账号不等于共享数据——每次解析必须落到显式资源范围。
 */
final class ResourceScope
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
    ) {}
}
