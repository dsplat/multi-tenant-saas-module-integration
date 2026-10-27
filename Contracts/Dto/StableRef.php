<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Contracts\Dto;

use InvalidArgumentException;

/**
 * 旧表适配的稳定引用：带模型命名空间的主键（`Model::class#<id>`）。
 *
 * 禁止虚构可跨模型复用的整数 ID；序列化后 ID 一律为字符串（JS 安全整数约束）。
 */
final class StableRef
{
    private function __construct(
        public readonly string $model,
        public readonly string $id,
    ) {}

    public static function make(string $model, int|string $id): self
    {
        if ($model === '' || (string) $id === '') {
            throw new InvalidArgumentException('StableRef requires model and id');
        }

        return new self($model, (string) $id);
    }

    public static function fromString(string $ref): self
    {
        $pos = strrpos($ref, '#');
        if ($pos === false || $pos === 0 || $pos === strlen($ref) - 1) {
            throw new InvalidArgumentException("invalid StableRef: {$ref}");
        }

        return new self(substr($ref, 0, $pos), substr($ref, $pos + 1));
    }

    public function toString(): string
    {
        return $this->model . '#' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
