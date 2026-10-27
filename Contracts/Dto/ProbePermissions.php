<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 探测权限：预检只能执行被授权的探测项，未授权项报 unknown/not_applicable，不得报 pass。
 */
final class ProbePermissions
{
    public function __construct(
        public readonly bool $allowExternalProbe = false,
        public readonly bool $allowSupplierMetaRead = false,
        public readonly bool $allowAccountMetaRead = false,
    ) {}
}
