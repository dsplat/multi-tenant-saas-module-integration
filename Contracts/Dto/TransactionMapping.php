<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 可信事务/订单映射：归属的最终依据（签名不能区分共用商户下的租户）。
 */
final class TransactionMapping
{
    public function __construct(
        public readonly StableRef $transactionRef,
        public readonly ConsumerContext $consumer,
        public readonly ?StableRef $bindingRef = null,
        public readonly ?ResourceScope $resource = null,
    ) {}
}
