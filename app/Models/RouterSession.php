<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live hotspot or PPPoE session as last reported by the router agent.
 * Replaced wholesale on each push (the router sends its current session list).
 */
class RouterSession extends Model
{
    public const KIND_HOTSPOT = 'hotspot';
    public const KIND_PPPOE = 'pppoe';

    protected $fillable = [
        'router_id',
        'kind',
        'username',
        'address',
        'mac_address',
        'uptime',
        'session_time_left',
        'bytes_in',
        'bytes_out',
        'pushed_at',
    ];

    protected $casts = [
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'pushed_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
