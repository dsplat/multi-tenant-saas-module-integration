<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集成同步运行记录（Integration Stage B / BL-163）
 *
 * 一次拉取/推送一条，承载结果统计与错误摘要；游标只在运行成功后推进。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_sync_runs')) {
            Schema::create('integration_sync_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('run_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->string('resource', 100)->comment('资源标识：workitems|members|documents...');
                $table->string('direction', 10)->comment('inbound|outbound');
                $table->string('trigger_type', 20)->comment('manual|schedule|webhook');
                $table->string('status', 20)->default('running')->comment('running|succeeded|failed');
                $table->timestamp('started_at')->comment('开始时间');
                $table->timestamp('finished_at')->nullable()->comment('结束时间');
                $table->json('stats')->nullable()->comment('处理统计（读取/写入/跳过数等）');
                $table->string('last_error', 1000)->nullable()->comment('失败原因摘要');
                $table->timestamps();

                $table->index(['tenant_id', 'connection_id', 'started_at'], 'integration_sync_runs_connection_index');
                $table->index(['tenant_id', 'status'], 'integration_sync_runs_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_runs');
    }
};
