<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 结构化拒绝原因（Playbook v1 §3.2 下限集合）。
 *
 * 业务异常与临时故障由 isTransient 区分；禁止用 null 同时代表全部失败，
 * 禁止把 disabled/revoked/invalid_credentials 解释为 unconfigured。
 */
enum FailureReason: string
{
    case Unconfigured = 'unconfigured';

    case Disabled = 'disabled';

    case Revoked = 'revoked';

    case InvalidCredentials = 'invalid_credentials';

    case AmbiguousBinding = 'ambiguous_binding';

    case ProviderUnavailable = 'provider_unavailable';

    case UnsupportedCapability = 'unsupported_capability';

    case ContextRequired = 'context_required';

    case Forbidden = 'forbidden';

    /**
     * 临时故障（可重试）与业务拒绝（改配置/授权才有解）的分界。
     */
    public function isTransient(): bool
    {
        return $this === self::ProviderUnavailable;
    }
}
