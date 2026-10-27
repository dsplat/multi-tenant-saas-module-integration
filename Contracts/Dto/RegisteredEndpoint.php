<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 已登记回调端点（验签输入之一）。端点登记与验证分离，验证不得授予租户权限。
 */
final class RegisteredEndpoint
{
    /** @param list<string> $allowedEventTypes */
    public function __construct(
        public readonly StableRef $endpointRef,
        public readonly string $url,
        public readonly array $allowedEventTypes = [],
    ) {}
}
