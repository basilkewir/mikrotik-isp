<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device (MAC) that has taken one of a hotspot account's slots.
 *
 * Together with RouterOS `shared-users` this is what "bind the account to N
 * devices" means: RouterOS refuses the (N+1)th concurrent login outright, and
 * this table decides which devices are allowed to hold the slots over time so
 * a dropped session cannot be silently taken over by a different device.
 */
class SubscriptionBoundDevice extends Model
{
    protected $table = 'subscription_bound_devices';

    protected $fillable = [
        'subscription_id',
        'mac_address',
        'bound_at',
        'last_seen_at',
        'is_active',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
