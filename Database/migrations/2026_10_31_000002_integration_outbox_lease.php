<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration Outbox 认领租约 / fencing（BL-180 P3，BL-185 R2）
 *
 * additive 增量：只加列与索引，不改写历史行。
 * - claim_token：每次认领生成的 fencing token，ACK/失败回写必须匹配；
 * - claim_count：认领次数（含租约到期回收），用于观测与区分新旧认领；
 * - claimed_at / lease_until：本次认领的开始与到期时刻；到期判断以数据库时间为准；
 * - attempts / last_error 沿用既有列，租约到期回收时消耗 attempts 并保留原因。
 *
 * 回填策略：无数据回填。存量 sending 行 lease_until 为 NULL，表示「结果不可知」，
 * 不被自动回收（Playbook §6：先对账，不能一律恢复 pending），由只读巡检
 * `IntegrationOutboxService::inspect()` 的 unconfirmed 列表暴露。
 *
 * 幂等：所有结构变更以 hasTable / hasColumn / hasIndex 判断，可重复执行。
 */
return new class extends Migration
{
    private const TABLE = 'integration_outbox_messages';

    private const LEASE_INDEX = 'integration_outbox_lease_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'claim_token')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('claim_token', 64)->nullable()
                    ->comment('当前认领 fencing token；ACK/失败必须匹配');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'claim_count')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedInteger('claim_count')->default(0)
                    ->comment('累计认领次数（含租约到期回收）');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'claimed_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('claimed_at')->nullable()
                    ->comment('本次认领开始时刻（数据库时间）');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'lease_until')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('lease_until')->nullable()
                    ->comment('本次认领租约到期时刻（数据库时间）');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::LEASE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['tenant_id', 'status', 'lease_until'], self::LEASE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::LEASE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::LEASE_INDEX);
            });
        }

        foreach (['lease_until', 'claimed_at', 'claim_count', 'claim_token'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
