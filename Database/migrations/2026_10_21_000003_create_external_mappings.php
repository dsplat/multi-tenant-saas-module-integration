<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系统集成模块（Integration）外部映射表（框架侧标准件，BL-163）
 *
 * 物理归位自 scrm `database/migrations/2025_01_03_000016_platform_module.php`：
 * - 表结构/唯一键/索引与 scrm 终态逐列对齐（含后续追加的 batch_id）；
 * - scrm 存量库**已有本表**，`hasTable` 守卫使本迁移整体跳过（第二道保险；
 *   第一道是迁移 basename 不同但表已存在）；
 * - 早期副本缺 batch_id 时按 `hasColumn` 幂等补齐（列 + 索引）。
 *
 * 列型一律 Schema Builder（MySQL/sqlite 可移植），不写裸 MySQL DDL。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('external_mappings')) {
            Schema::create('external_mappings', function (Blueprint $table) {
                $table->unsignedBigInteger('mapping_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('source_system', 50)->comment('外部系统：xiaoe|jingdaka|other');
                $table->string('source_type', 50)->comment('外部资源类型：course|catalog|student|order|clock_activity|submission|exam...');
                $table->string('source_id', 100)->comment('外部系统资源 ID');
                $table->string('local_type', 50)->comment('本地落库实体类型：course|activity|user|order|submission...');
                $table->unsignedBigInteger('local_id')->comment('本地业务 ID');
                $table->string('batch_id', 64)->nullable()->comment('导入批次（manifest.batch_id，按批次撤回/对账）');
                $table->json('raw_snapshot')->nullable()->comment('导入时原始数据快照（对账/追溯）');
                $table->timestamps();

                $table->unique(['tenant_id', 'source_system', 'source_type', 'source_id'], 'external_mappings_source_unique');
                $table->index(['tenant_id', 'local_type', 'local_id']);
                $table->index(['tenant_id', 'batch_id']);
            });
        }

        if (Schema::hasTable('external_mappings') && ! Schema::hasColumn('external_mappings', 'batch_id')) {
            Schema::table('external_mappings', function (Blueprint $table) {
                $table->string('batch_id', 64)->nullable()->after('local_id')->comment('导入批次（manifest.batch_id，按批次撤回/对账）');
                $table->index(['tenant_id', 'batch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_mappings');
    }
};
