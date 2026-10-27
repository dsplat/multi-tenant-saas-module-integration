<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts;

/**
 * 外部服务治理契约版本（WP-C / OPM-09 冻结）。
 *
 * ResolvedService 等序列化对象必须携带 contract_version；后续接口形状变化
 * 另出版本化 amendment 并经集成负责人审阅（Playbook v1 §3.2）。
 */
final class ServiceContract
{
    public const VERSION = '1.0';
}
