<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * 系统集成模块（BL-163 提升 / 框架侧标准件）
 *
 * Stage A 只提供外部映射存储（external_mappings + ExternalMapping +
 * ExternalMappingService），不挂路由、不注册 AI 工具。连接/游标/收发件箱
 * 等同步基础设施按 Stage B（NWU P0 随 Yunxiao 落）新增，避免把新设计与
 * 本次物理归位混在一起。
 *
 * 物理归位自 scrm `App\Modules\TenantOps`：换仓库位 + vendor 名，服务语义不变。
 */
class IntegrationServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'integration';
}
