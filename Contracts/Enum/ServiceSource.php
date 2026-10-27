<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 服务来源：租户自定义方案 vs 显式继承平台默认（inherit_platform 是解析结果，不是接入落位）。
 */
enum ServiceSource: string
{
    case Tenant = 'tenant';

    case InheritPlatform = 'inherit_platform';
}
