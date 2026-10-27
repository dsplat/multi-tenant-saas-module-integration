<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Closure;
use MultiTenantSaas\Modules\Integration\Contracts\CallbackVerifierContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\RawRequest;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\RegisteredEndpoint;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\VerificationResult;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * 回调验签桥（WP-H）：领域验签器 + 防重放收口。
 *
 * 不代替领域验签——原始字节验签由注入的领域验签器完成（微信/支付/IdP 各自协议）；
 * 本桥只做共性收口：候选账号必须是有限集、证据时间窗、nonce 一次性消费。
 * 不授予租户权限（归属由 CallbackResolver 结合可信事务映射确定）。
 */
class CallbackBridge implements CallbackVerifierContract
{
    /**
     * @param  Closure(RegisteredEndpoint, RawRequest, list<StableRef>): VerificationResult  $domainVerifier
     */
    public function __construct(
        private readonly CallbackGuard $guard,
        private readonly Closure $domainVerifier,
    ) {}

    public function verify(RegisteredEndpoint $endpoint, RawRequest $request, array $candidateAccounts): VerificationResult
    {
        if ($candidateAccounts === []) {
            return VerificationResult::rejected(new Rejection(
                FailureReason::ContextRequired,
                '候选账号为空：禁止全表扫描式归属',
            ));
        }

        $result = ($this->domainVerifier)($endpoint, $request, $candidateAccounts);

        if (! $result->isVerified()) {
            return $result;
        }

        $evidence = $result->evidence;

        if (! $this->guard->withinWindow($evidence->occurredAt)) {
            return VerificationResult::rejected(new Rejection(
                FailureReason::Forbidden,
                '证据超出时间窗（重放/过期）',
            ));
        }

        $nonce = (string) ($evidence->attributes['nonce'] ?? '');
        if ($nonce !== '' && ! $this->guard->consumeNonce($nonce)) {
            return VerificationResult::rejected(new Rejection(
                FailureReason::Forbidden,
                'state/nonce 已消费（重放）',
            ));
        }

        return $result;
    }
}
