<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Enum;

/**
 * 预检分项结果：unknown 不得报 pass（Playbook v1 WP-H 约束）。
 */
enum PreflightStatus: string
{
    case Pass = 'pass';

    case Fail = 'fail';

    case Unknown = 'unknown';

    case NotApplicable = 'not_applicable';
}
