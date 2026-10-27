<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Fakes;

use MultiTenantSaas\Modules\Integration\Contracts\Dto\StableRef;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageAttributionResult;
use MultiTenantSaas\Modules\Integration\Contracts\Dto\UsageEvent;
use MultiTenantSaas\Modules\Integration\Contracts\UsageAttributionContract;

/**
 * fake 记账归集器：按 eventKey 幂等；重复事件返回 idempotentReplay=true。
 * 未知成本照实保留（CostSource::Unknown）。
 */
final class FakeUsageAttribution implements UsageAttributionContract
{
    /** @var array<string, UsageEvent> */
    private array $events = [];

    /** @var array<string, UsageAttributionResult> */
    private array $results = [];

    public function record(UsageEvent $event): UsageAttributionResult
    {
        if (isset($this->results[$event->eventKey])) {
            $prior = $this->results[$event->eventKey];

            return new UsageAttributionResult(
                eventKey: $prior->eventKey,
                costSource: $prior->costSource,
                accountingRef: $prior->accountingRef,
                idempotentReplay: true,
            );
        }

        $this->events[$event->eventKey] = $event;

        $result = new UsageAttributionResult(
            eventKey: $event->eventKey,
            costSource: $event->costSource,
            accountingRef: StableRef::make('FakeAccounting', $event->eventKey),
        );
        $this->results[$event->eventKey] = $result;

        return $result;
    }

    /** @return list<UsageEvent> */
    public function recorded(): array
    {
        return array_values($this->events);
    }
}
