<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 成本来源：已知（带价格版本）/ 未知。未知成本保持未知，不得猜成默认价。
 */
enum CostSource: string
{
    case Known = 'known';

    case Unknown = 'unknown';
}
