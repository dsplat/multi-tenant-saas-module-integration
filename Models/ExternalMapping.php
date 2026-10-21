<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 外部系统映射（外部资源 ID ↔ 本地实体）
 *
 * 迁移工具永远在项目外（生成固定格式数据导入），本表仅保留映射关系。
 * 框架标准件（BL-163）：自 scrm TenantOps 物理归位，表/列/唯一键语义不变。
 */
class ExternalMapping extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    // 已知外部系统（字符串枚举，新增系统扩枚举值即可）
    public const SOURCE_XIAOE = 'xiaoe';

    public const SOURCE_JINGDAKA = 'jingdaka';

    public const SOURCE_OTHER = 'other';

    protected $table = 'external_mappings';

    protected $primaryKey = 'mapping_id';

    protected $fillable = [
        'tenant_id', 'source_system', 'source_type', 'source_id',
        'local_type', 'local_id', 'raw_snapshot', 'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'raw_snapshot' => 'array',
        ];
    }
}
