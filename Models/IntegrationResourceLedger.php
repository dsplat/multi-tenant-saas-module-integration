<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 稀缺资源台账行（WP-H / ADR §1.3）
 *
 * scope=supplier_rule 行 tenant_id=NULL（平台范围规则，非租户通配授权）；
 * scope=account_instance 行记录某账号的资源实例（占用/修改权/实测值）。
 * 不挂 BelongsToTenant：平台范围行必须由调用方显式声明查询范围。
 */
class IntegrationResourceLedger extends Model
{
    use HasGlobalId, SerializesFriendlyDates;

    public const SCOPE_SUPPLIER_RULE = 'supplier_rule';

    public const SCOPE_ACCOUNT_INSTANCE = 'account_instance';

    public const KIND_DOMAIN_SLOT = 'domain_slot';

    public const KIND_CALLBACK_ENDPOINT = 'callback_endpoint';

    public const KIND_EXPORT_IP = 'export_ip';

    public const KIND_QUALIFICATION = 'qualification';

    public const KIND_AUTHORIZATION = 'authorization';

    public const KINDS = [
        self::KIND_DOMAIN_SLOT,
        self::KIND_CALLBACK_ENDPOINT,
        self::KIND_EXPORT_IP,
        self::KIND_QUALIFICATION,
        self::KIND_AUTHORIZATION,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_DRIFTED = 'drifted';

    public const STATUS_UNKNOWN = 'unknown';

    public const RIGHTS_OURS = 'ours';

    public const RIGHTS_SUPPLIER = 'supplier';

    public const RIGHTS_NONE = 'none';

    public const RIGHTS_UNKNOWN = 'unknown';

    protected $table = 'integration_resource_ledger';

    protected $primaryKey = 'ledger_id';

    protected $fillable = [
        'tenant_id', 'scope', 'capability_key', 'provider', 'environment',
        'resource_kind', 'slot_key', 'account_ref', 'expected_value', 'observed_value',
        'slots_used', 'slots_total', 'modify_rights', 'status',
        'reserved_by', 'reserved_until', 'evidence_at', 'review_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'array',
            'observed_value' => 'array',
            'metadata' => 'array',
            'reserved_until' => 'datetime',
            'evidence_at' => 'datetime',
            'review_by' => 'datetime',
        ];
    }
}
