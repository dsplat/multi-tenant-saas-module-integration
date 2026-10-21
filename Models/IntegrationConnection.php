<?php

namespace MultiTenantSaas\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 集成连接（租户对某个外部系统的一套访问配置）
 *
 * 只存配置与凭据，不含厂商 API 逻辑；凭据经 `encrypted:array` 落库。
 * 框架标准件（BL-163 Stage B）。
 */
class IntegrationConnection extends Model
{
    use BelongsToTenant, HasGlobalId, SerializesFriendlyDates;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const AUTH_NONE = 'none';

    public const AUTH_BEARER = 'bearer';

    public const AUTH_BASIC = 'basic';

    public const AUTH_OAUTH = 'oauth';

    protected $table = 'integration_connections';

    protected $primaryKey = 'connection_id';

    protected $fillable = [
        'tenant_id', 'name', 'provider', 'base_url', 'auth_type',
        'credentials', 'config', 'status', 'last_checked_at', 'last_check_error',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
