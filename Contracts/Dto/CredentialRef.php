<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 凭证引用：稳定引用 + 版本号。
 *
 * 密钥轮换只产生新 version，accountRef 不变（账号身份与密钥版本解耦）。
 */
final class CredentialRef
{
    public function __construct(
        public readonly StableRef $ref,
        public readonly string $version,
    ) {}

    public function toString(): string
    {
        return $this->ref->toString() . '@' . $this->version;
    }
}
