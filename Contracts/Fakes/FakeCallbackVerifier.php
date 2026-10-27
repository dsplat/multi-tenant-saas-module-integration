<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\CallbackVerifierContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\RawRequest;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\RegisteredEndpoint;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\VerificationResult;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * fake 验签器：预设结果优先，缺省拒绝（fail-closed），记录调用。
 */
final class FakeCallbackVerifier implements CallbackVerifierContract
{
    private ?VerificationResult $result = null;

    /** @var list<array{endpoint: RegisteredEndpoint, request: RawRequest, candidates: list<StableRef>}> */
    public array $calls = [];

    public function willReturn(VerificationResult $result): void
    {
        $this->result = $result;
    }

    public function verify(RegisteredEndpoint $endpoint, RawRequest $request, array $candidateAccounts): VerificationResult
    {
        $this->calls[] = compact('endpoint', 'request', 'candidateAccounts');

        return $this->result ?? VerificationResult::rejected(new Rejection(
            FailureReason::InvalidCredentials,
            'fake verifier default: reject',
        ));
    }
}
