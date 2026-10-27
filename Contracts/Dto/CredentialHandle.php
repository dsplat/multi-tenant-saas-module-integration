<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use LogicException;

/**
 * 进程内凭证句柄：只允许显式 reveal() 取用明文，永不序列化。
 *
 * json_encode 只会输出 {}（私有属性不参与）；serialize() 直接拒绝，
 * 防止缓存/队列/日志把密钥带走（Playbook v1 §3.2）。
 */
final class CredentialHandle
{
    private function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {}

    public static function revealable(#[\SensitiveParameter] string $secret): self
    {
        return new self($secret);
    }

    public function reveal(): string
    {
        return $this->secret;
    }

    public function __serialize(): array
    {
        throw new LogicException('CredentialHandle must not be serialized');
    }

    public function __debugInfo(): array
    {
        return ['secret' => '***'];
    }
}
