<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集成同步游标（Integration Stage B / BL-163）
 *
 * 每个连接 + 资源一条，记录增量拉取的起点（时间戳/序号/令牌均可，统一存字符串）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_sync_cursors')) {
            Schema::create('integration_sync_cursors', function (Blueprint $table) {
                $table->unsignedBigInteger('cursor_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->string('resource', 100)->comment('资源标识：workitems|members|documents...');
                $table->text('cursor_value')->nullable()->comment('外部系统游标原值');
                $table->json('cursor_meta')->nullable()->comment('游标附带信息（分页/版本等）');
                $table->timestamp('last_synced_at')->nullable()->comment('最近一次成功推进时间');
                $table->timestamps();

                $table->unique(['connection_id', 'resource'], 'integration_sync_cursors_resource_unique');
                $table->index(['tenant_id', 'connection_id'], 'integration_sync_cursors_connection_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_cursors');
    }
};
