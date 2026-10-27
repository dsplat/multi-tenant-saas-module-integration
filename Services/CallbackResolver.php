<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Services;

use Closure;
use MultiTenantSaas\Modules\Integration\Contracts\CallbackResolverContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackEvidence;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackResolution;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\TransactionMapping;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * 回调归属解析（WP-H / OPM-10「候选定位 → 验证 → 交叉校验」）。
 *
 * 签名有效不等于归属成立：证据账号必须与可信事务映射的绑定账号一致，
 * 「签名有效但商户/订单归属错」按 forbidden 拒绝；供应商验签公钥不作租户凭证。
 */
class CallbackResolver implements CallbackResolverContract
{
    /**
     * @param  Closure(StableRef): ?StableRef  $accountBinder  绑定 → 账号 的可信映射（由领域绑定解析提供）
     */
    public function __construct(
        private readonly Closure $accountBinder,
    ) {}

    public function resolve(CallbackEvidence $evidence, TransactionMapping $mapping): CallbackResolution|Rejection
    {
        if ($mapping->bindingRef === null) {
            return new Rejection(FailureReason::Unconfigured, '事务未关联绑定，归属不可解析');
        }

        $boundAccount = ($this->accountBinder)($mapping->bindingRef);

        if ($boundAccount === null) {
            return new Rejection(FailureReason::Unconfigured, '绑定未指向有效账号');
        }

        if (! $boundAccount->equals($evidence->accountRef)) {
            // 签名有效但商户/订单归属错：拒绝而不是迁移归属
            return new Rejection(
                FailureReason::Forbidden,
                '证据账号与事务绑定账号不一致',
                details: ['evidence_account' => $evidence->accountRef->toString(), 'bound_account' => $boundAccount->toString()],
            );
        }

        return new CallbackResolution(
            consumer: $mapping->consumer,
            accountRef: $evidence->accountRef,
            event: $evidence->eventType,
            correlationId: $mapping->consumer->correlationId,
            bindingRef: $mapping->bindingRef,
            resource: $mapping->resource,
        );
    }
}
