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
        'is_default',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'validity_days' => 'integer',
        'data_limit_mb' => 'integer',
        'shared_users' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /** Validity of the default plan: 30 calendar days (see CalendarWindow). */
    public const DEFAULT_VALIDITY_DAYS = 30;

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The plan new subscribers get when none is chosen. Null when none is set.
     */
    public static function defaultPlan(): ?self
    {
        return static::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Make this the single default plan. Only plans valid for 30 calendar days
     * may be the default.
     *
     * @throws \InvalidArgumentException
     */
    public function makeDefault(): void
    {
        if ((int) $this->validity_days !== self::DEFAULT_VALIDITY_DAYS) {
            throw new \InvalidArgumentException(
                'The default plan must be valid for ' . self::DEFAULT_VALIDITY_DAYS . ' calendar days.'
            );
        }

        \Illuminate\Support\Facades\DB::transaction(function () {
            static::query()->where('is_default', true)->update(['is_default' => false]);
            $this->forceFill(['is_default' => true])->save();
        });
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
