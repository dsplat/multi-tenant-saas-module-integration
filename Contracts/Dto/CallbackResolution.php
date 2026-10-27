<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 回调归属结果：消费方、账号/绑定、资源、事件与关联 ID。
 */
final class CallbackResolution
{
    public function __construct(
        public readonly ConsumerContext $consumer,
        public readonly StableRef $accountRef,
        public readonly string $event,
        public readonly string $correlationId,
        public readonly ?StableRef $bindingRef = null,
        public readonly ?ResourceScope $resource = null,
    ) {}
}
