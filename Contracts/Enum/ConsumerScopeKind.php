<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 消费方范围：租户业务 vs 平台运维。
 *
 * 平台运维消耗必须显式声明运维用途与授权，不得用 MAX-ID 初始化租户填空
 * （Playbook v1 §3.1-2）。
 */
enum ConsumerScopeKind: string
{
    case Tenant = 'tenant';

    case PlatformOperation = 'platform_operation';
}
