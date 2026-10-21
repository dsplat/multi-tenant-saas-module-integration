<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集成收件箱（Integration Stage B / BL-163）
 *
 * 外部系统推送/拉取到的事件先落库，再由消费方按状态推进。
 * `(connection_id, external_event_id)` 唯一：同一外部事件的重复投递只留一条。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_inbox_events')) {
            Schema::create('integration_inbox_events', function (Blueprint $table) {
                $table->unsignedBigInteger('event_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('connection_id');
                $table->string('external_event_id', 191)->comment('外部事件ID（幂等键）');
                $table->string('event_type', 100)->comment('事件类型：workitem.updated|push...');
                $table->json('payload')->nullable()->comment('外部事件原始数据');
                $table->string('status', 20)->default('pending')->comment('pending|processed|failed|ignored');
                $table->unsignedInteger('attempts')->default(0)->comment('消费尝试次数');
                $table->string('last_error', 1000)->nullable()->comment('最近一次消费失败原因');
                $table->timestamp('received_at')->comment('外部事件发生/接收时间');
                $table->timestamp('processed_at')->nullable()->comment('消费完成时间');
                $table->timestamps();

                $table->unique(['connection_id', 'external_event_id'], 'integration_inbox_event_unique');
                $table->index(['tenant_id', 'status'], 'integration_inbox_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_inbox_events');
    }
};
