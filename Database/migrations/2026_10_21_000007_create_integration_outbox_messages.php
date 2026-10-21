<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集成发件箱（Integration Stage B / BL-163）
 *
 * 平台对外的写操作先落库再投递，保证「业务提交」与「外部写入」解耦且可重试。
 * `(connection_id, idempotency_key)` 唯一：调用方用业务幂等键保证不重复下发。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_outbox_messages')) {
            Schema::create('integration_outbox_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('message_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->string('idempotency_key', 191)->comment('调用方幂等键（同一连接内唯一）');
                $table->string('action', 100)->comment('外部操作名：project.create|member.add...');
                $table->json('payload')->nullable()->comment('外部操作入参');
                $table->string('status', 20)->default('pending')->comment('pending|sending|sent|failed|dead');
                $table->unsignedInteger('attempts')->default(0)->comment('投递尝试次数');
                $table->timestamp('available_at')->comment('最早可投递时间（退避重试用）');
                $table->string('last_error', 1000)->nullable()->comment('最近一次投递失败原因');
                $table->string('external_ref', 191)->nullable()->comment('外部返回的资源标识');
                $table->timestamp('sent_at')->nullable()->comment('投递成功时间');
                $table->timestamps();

                $table->unique(['connection_id', 'idempotency_key'], 'integration_outbox_idempotency_unique');
                $table->index(['tenant_id', 'status', 'available_at'], 'integration_outbox_due_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox_messages');
    }
};
