<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

/**
 * 原始回调请求（inspect-body 级输入）：仅协议 verifier 可读正文，
 * 不写原文日志（Playbook v1 §4 处理层表）。
 */
final class RawRequest
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly string $method,
        public readonly array $headers,
        public readonly string $body,
    ) {}
}
