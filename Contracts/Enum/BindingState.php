<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 服务绑定状态机（WP-C 冻结的显式转换表）。
 *
 * owner 与 consumer 分离；密钥轮换只能改变 credentialVersion，不能改变账号身份
 * （Playbook v1 §3.2）。任意未列入 TRANSITIONS 的迁移一律非法。
 */
enum BindingState: string
{
    case Draft = 'draft';

    case Validated = 'validated';

    case Active = 'active';

    case Suspended = 'suspended';

    case Revoked = 'revoked';

    case Retired = 'retired';

    /** @var array<string, list<string>> 允许的迁移表（from => [to, ...]） */
    private const TRANSITIONS = [
        'draft' => ['validated', 'retired'],
        'validated' => ['active', 'draft', 'retired'],
        'active' => ['suspended', 'revoked', 'retired'],
        'suspended' => ['active', 'revoked', 'retired'],
        'revoked' => ['retired'],
        'retired' => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->value] === [];
    }
}
