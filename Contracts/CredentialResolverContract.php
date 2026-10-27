<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\CredentialHandle;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CredentialRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;

/**
 * 凭证加载：已授权服务引用 + 凭证引用/版本 → 仅进程内句柄。
 *
 * 明文只经 CredentialHandle::reveal() 取用；任何序列化/日志/审计输出都不得
 * 携带凭证原文（Playbook v1 §3.2）。
 */
interface CredentialResolverContract
{
    public function load(StableRef $serviceRef, CredentialRef $credentialRef): CredentialHandle;
}
