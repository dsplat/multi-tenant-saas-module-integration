<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 稀缺资源台账（WP-H / OPM-10/11）
 *
 * 对齐 ADR §1.3：区分「供应商规则」（tenant_id=NULL 的平台范围行）与
 * 「账号资源实例」（tenant_id 所属）；记录域名槽位/回调端点/出口 IP/资质/授权
 * 的期望值、实测值、占用与修改权，供预检（PreflightInspector）与漂移告警使用。
 *
 * 本地预约（reserved_by/reserved_until）只防本平台并发占用，不代表锁住供应商
 * 后台；激活前须再核对外部实际值。
 *
 * 幂等：hasTable 守卫，重复执行安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_resource_ledger')) {
            return;
        }

        Schema::create('integration_resource_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('ledger_id')->primary()->comment('IdGenerator 全局ID');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('NULL=供应商规则/平台范围，非通配授权');
            $table->string('scope', 20)->comment('supplier_rule | account_instance');
            $table->string('capability_key', 50);
            $table->string('provider', 50)->default('');
            $table->string('environment', 20)->default('production');
            $table->string('resource_kind', 30)->comment('domain_slot | callback_endpoint | export_ip | qualification | authorization');
            $table->string('slot_key', 100)->nullable()->comment('账号内槽位标识');
            $table->string('account_ref', 255)->nullable()->comment('StableRef 字符串 Model::class#id');
            $table->text('expected_value')->nullable()->comment('JSON：供应商要求的登记值/配额');
            $table->text('observed_value')->nullable()->comment('JSON：实测/回读值，用于漂移检测');
            $table->unsignedInteger('slots_used')->nullable();
            $table->unsignedInteger('slots_total')->nullable();
            $table->string('modify_rights', 20)->nullable()->comment('ours | supplier | none | unknown');
            $table->string('status', 20)->default('active')->comment('active | released | drifted | unknown');
            $table->string('reserved_by', 100)->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('evidence_at')->nullable()->comment('证据时间');
            $table->timestamp('review_by')->nullable()->comment('复核有效期');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['capability_key', 'resource_kind', 'status'], 'irl_cap_kind_status');
            $table->index(['account_ref', 'resource_kind'], 'irl_account_kind');
            $table->index('tenant_id', 'irl_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_resource_ledger');
    }
};
