<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\CallbackResolverContract;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackEvidence;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\CallbackResolution;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\Rejection;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\TransactionMapping;
use MultiTenantSaas\Modules\Integration\Contracts\Enum\FailureReason;

/**
 * fake 归属解析器：预设结果优先；缺省按映射的消费方/绑定组装成功结果。
 */
final class FakeCallbackResolver implements CallbackResolverContract
{
    private CallbackResolution|Rejection|null $result = null;

    /** @var list<array{evidence: CallbackEvidence, mapping: TransactionMapping}> */
    public array $calls = [];

    public function willReturn(CallbackResolution|Rejection $result): void
    {
        $this->result = $result;
    }

    public function resolve(CallbackEvidence $evidence, TransactionMapping $mapping): CallbackResolution|Rejection
    {
        $this->calls[] = compact('evidence', 'mapping');

        if ($this->result !== null) {
            return $this->result;
        }

        if ($mapping->bindingRef === null) {
            return new Rejection(FailureReason::Unconfigured, 'fake resolver default: no binding in mapping');
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
