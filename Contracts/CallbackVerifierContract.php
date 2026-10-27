<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\RawRequest;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\RegisteredEndpoint;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\VerificationResult;

/**
 * 回调验签：已登记端点 + 原始请求 + 有限候选账号 → 已验证证据或拒绝。
 *
 * 只做协议验证（原始字节验签、时间窗、nonce 一次性消费），不授予租户权限；
 * 实现需可注入时钟/随机源，生产不得使用确定性 token（Playbook v1 §3.2）。
 */
interface CallbackVerifierContract
{
    /**
     * @param  list<StableRef>  $candidateAccounts  有限候选账号（不得全表扫描）
     */
    public function verify(RegisteredEndpoint $endpoint, RawRequest $request, array $candidateAccounts): VerificationResult;
}
