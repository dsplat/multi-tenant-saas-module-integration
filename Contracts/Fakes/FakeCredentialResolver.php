<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\CredentialResolverContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CredentialHandle;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CredentialRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use RuntimeException;

/**
 * fake 凭证加载器：内存映射（ref@version => 明文），记录加载历史但不记录明文。
 */
final class FakeCredentialResolver implements CredentialResolverContract
{
    /** @var array<string, string> */
    private array $secrets = [];

    /** @var list<string> */
    public array $loaded = [];

    public function put(CredentialRef $ref, #[\SensitiveParameter] string $secret): void
    {
        $this->secrets[$ref->toString()] = $secret;
    }

    public function load(StableRef $serviceRef, CredentialRef $credentialRef): CredentialHandle
    {
        $this->loaded[] = $credentialRef->toString();

        $secret = $this->secrets[$credentialRef->toString()] ?? null;
        if ($secret === null) {
            throw new RuntimeException("credential not provisioned: {$credentialRef->toString()}");
        }

        return CredentialHandle::revealable($secret);
    }
}
