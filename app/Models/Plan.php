<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'service_type',
        'billing_mode',
        'price',
        'validity_days',
        'data_limit_mb',
        'rate_limit_upload',
        'rate_limit_download',
        'hotspot_profile',
        'pppoe_profile',
        'shared_users',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'validity_days' => 'integer',
        'data_limit_mb' => 'integer',
        'shared_users' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Compute data quota in bytes.
     */
    public function getDataLimitBytesAttribute(): ?int
    {
        return $this->data_limit_mb ? $this->data_limit_mb * 1024 * 1024 : null;
    }

    /**
     * Get MikroTik rate limit string formatted as upload/download (e.g. 5M/10M).
     */
    public function getRateLimitStringAttribute(): string
    {
        return "{$this->rate_limit_upload}/{$this->rate_limit_download}";
    }
}
