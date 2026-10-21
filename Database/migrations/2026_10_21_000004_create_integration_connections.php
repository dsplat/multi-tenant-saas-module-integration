<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集成连接（Integration Stage B / BL-163）
 *
 * 一条记录 = 一个租户对某个外部系统的一套访问配置。模块只存连接与凭据，
 * 不含任何厂商 API 逻辑；调用与投影由各自连接器模块实现。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_connections')) {
            Schema::create('integration_connections', function (Blueprint $table) {
                $table->unsignedBigInteger('connection_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 100)->comment('租户内唯一标识');
                $table->string('provider', 50)->comment('外部系统标识：yunxiao|gitlab|custom...');
                $table->string('base_url', 500)->nullable()->comment('接口根地址');
                $table->string('auth_type', 30)->default('none')->comment('none|bearer|basic|oauth');
                $table->text('credentials')->nullable()->comment('凭据（encrypted:array 加密存储）');
                $table->json('config')->nullable()->comment('非敏感连接参数');
                $table->string('status', 20)->default('active')->comment('active|disabled');
                $table->timestamp('last_checked_at')->nullable()->comment('最近一次连通性检查');
                $table->string('last_check_error', 1000)->nullable()->comment('最近一次检查的错误摘要');
                $table->timestamps();

                $table->unique(['tenant_id', 'name'], 'integration_connections_name_unique');
                $table->index(['tenant_id', 'provider'], 'integration_connections_provider_index');
                $table->index(['tenant_id', 'status'], 'integration_connections_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
